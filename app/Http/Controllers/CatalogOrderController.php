<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CatalogOrderController extends Controller
{
    /** Verify the authenticated user owns this shop. */
    private function authorizeShop(int $shopId): \App\Models\Shop
    {
        $shop = \App\Models\Shop::findOrFail($shopId);
        $user = Auth::user();

        // Must be the shop owner or a staff/branch_manager belonging to this shop
        $isOwner   = $user->id === $shop->owner_id;
        $isStaff   = $user->staffProfile && $user->staffProfile->shop_id === $shop->id;

        if (!$isOwner && !$isStaff) {
            abort(403, 'Unauthorized: You do not have access to this shop.');
        }

        return $shop;
    }

    public function index($shopId, Request $request)
    {
        $this->authorizeShop($shopId);

        $query = \App\Models\CatalogOrder::with(['catalogItem.images', 'customer', 'branch:id,name'])
            ->where('shop_id', $shopId);

        // Same "pinned staff only see their own branch" scoping already
        // used for jobs/appointments/customers — walk-in orders were the
        // one order type left without any branch attribution at all. Also
        // matches null-branch rows (every order that existed before this
        // column was added) so migrating in doesn't suddenly hide a branch
        // staff member's own pre-existing order history.
        $user = $request->user();
        if (!$user->hasRole('shop_owner') && $user->staffProfile?->shop_branch_id) {
            $branchId = $user->staffProfile->shop_branch_id;
            $query->where(fn ($q) => $q->where('shop_branch_id', $branchId)->orWhereNull('shop_branch_id'));
        } elseif ($request->filled('branch_id')) {
            $query->where('shop_branch_id', $request->branch_id);
        }

        $orders = $query->latest()->get();

        return response()->json(['data' => $orders]);
    }

    /**
     * Walk-in only — a quick, immediate-sale record for a customer who came
     * into the shop and ordered off the Design Catalog (as a made-to-order
     * reference). Store pickup only, matching the rest of the system's
     * exclusion of logistics/courier/delivery management.
     */
    public function store(Request $request, $shopId)
    {
        $shop = $this->authorizeShop($shopId);

        $validated = $request->validate([
            'catalog_item_id' => [
                'required',
                Rule::exists('catalog_items', 'id')->where('shop_id', $shopId),
            ],
            'shop_branch_id' => ['nullable', Rule::exists('shop_branches', 'id')->where('shop_id', $shopId)],
            'selected_size'  => 'nullable|string|max:50',
            'customer_id'    => 'nullable|exists:users,id',
            'total_amount'   => 'required|numeric|min:0',
            'payment_status' => 'required|in:pending,paid',
            // Model/migration have carried these three columns since day
            // one, and the Payments page's "Receipts to Verify" queue is
            // already built to expect them (usePayments.ts checks
            // payment_method !== 'cash' && payment_status === 'pending' to
            // decide whether a catalog order needs verification) — but this
            // endpoint never accepted them, so a real GCash/bank walk-in
            // sale had no way to actually record its reference/receipt,
            // and could never appear in that verification queue at all.
            'payment_method'       => 'nullable|string|in:cash,gcash,bank_transfer',
            'payment_reference'    => 'nullable|string|max:255',
            'payment_receipt_path' => 'nullable|string|max:2048',
        ]);

        // Same "auto-assign when there's only one branch" convenience
        // AppointmentController::store already gives owners/managers —
        // don't make a single-branch shop pick from a dropdown of one.
        if (empty($validated['shop_branch_id'])) {
            $userBranchId = $request->user()->staffProfile?->shop_branch_id;
            if ($userBranchId) {
                $validated['shop_branch_id'] = $userBranchId;
            } elseif ($shop->branches()->count() === 1) {
                $validated['shop_branch_id'] = $shop->branches()->first()->id;
            }
        }

        $validated['shop_id']           = $shopId;
        $validated['type']              = 'walkin';
        $validated['status']            = 'ready';
        $validated['fulfillment_type']  = 'pickup';

        // Same reused-screenshot protection as JobOrderController@pay —
        // now that this endpoint actually records a reference (see above),
        // it needs the same check, and has to look across all three real
        // payment surfaces at this shop (job order payments, other walk-in
        // orders, appointment deposits), not just its own table, since a
        // customer could just as easily reuse one screenshot across any of
        // them at the same counter.
        $duplicateReferenceWarning = null;
        if (!empty($validated['payment_reference'])) {
            $ref = $validated['payment_reference'];
            $dupJobOrder = \App\Models\JobOrder::where('shop_id', $shopId)
                ->whereHas('payments', fn ($q) => $q->where('reference', $ref)->whereNull('rejected_at'))
                ->first();
            $dupCatalogOrder = $dupJobOrder ? null : \App\Models\CatalogOrder::where('shop_id', $shopId)
                ->where('payment_reference', $ref)
                ->first();
            $dupAppointment = ($dupJobOrder || $dupCatalogOrder) ? null : \App\Models\Appointment::where('shop_id', $shopId)
                ->where('payment_reference', $ref)
                ->first();

            if ($dupJobOrder) {
                $duplicateReferenceWarning = "This reference number was already used on job order {$dupJobOrder->order_number} — double-check this isn't a reused receipt before accepting.";
            } elseif ($dupCatalogOrder) {
                $duplicateReferenceWarning = "This reference number was already used on order #{$dupCatalogOrder->id} — double-check this isn't a reused receipt before accepting.";
            } elseif ($dupAppointment) {
                $duplicateReferenceWarning = "This reference number was already used on an appointment deposit — double-check this isn't a reused receipt before accepting.";
            }
        }

        $order = \App\Models\CatalogOrder::create($validated);

        // Notify shop owner of the new order (mirrors Job Orders/Appointments,
        // which already notify regardless of who — owner or staff — logged it).
        $shop = \App\Models\Shop::find($shopId);
        $shopOwner = $shop?->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\NewCatalogOrderNotification($order));
        }

        return response()->json([
            'data' => $order->load(['catalogItem', 'customer', 'branch:id,name']),
            'warning' => $duplicateReferenceWarning,
        ], 201);
    }

    public function update(Request $request, $shopId, $orderId)
    {
        $this->authorizeShop($shopId);

        $order = \App\Models\CatalogOrder::where('shop_id', $shopId)->findOrFail($orderId);

        $validated = $request->validate([
            'status'         => 'required|in:pending,ready,completed,cancelled',
            'payment_status' => 'sometimes|in:pending,paid',
        ]);

        // A cancelled order voids a mistaken/duplicate entry — once the item has actually
        // moved (handed over / picked up) it must be handled through the normal lifecycle
        // instead, not silently erased. Walk-in orders (store()) are always created
        // directly as 'ready', never 'pending' — 'pending' only exists as a possible
        // future status for a non-instant order type — so 'ready' has to be a
        // cancellable state too, or every walk-in order becomes uncancellable forever.
        if ($validated['status'] === 'cancelled' && !in_array($order->status, ['pending', 'ready'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a pending or ready order can be cancelled.',
            ], 422);
        }

        $order->update($validated);

        return response()->json(['data' => $order->load(['catalogItem', 'customer', 'branch:id,name'])]);
    }

    /**
     * A one-time, in-the-moment discount the owner grants on an existing
     * walk-in order (e.g. a repeat customer) — not a standing coupon/promo
     * code. Catalog orders have no separate balance column, so the discount
     * reduces total_amount directly. Logged to the audit trail, same pattern
     * as JobOrderController::applyDiscount / AppointmentController's audit entries.
     */
    public function applyDiscount(Request $request, $shopId, $orderId)
    {
        $shop = $this->authorizeShop($shopId);

        $order = \App\Models\CatalogOrder::where('shop_id', $shopId)->findOrFail($orderId);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        $discountAmount = (float) $validated['amount'];
        $currentTotal = (float) $order->total_amount;

        if ($discountAmount > $currentTotal) {
            return response()->json([
                'success' => false,
                'message' => 'Discount cannot exceed the order total (₱' . number_format($currentTotal, 2) . ').',
            ], 400);
        }

        $newTotal = round($currentTotal - $discountAmount, 2);
        $newDiscountTotal = round((float) ($order->discount_amount ?? 0) + $discountAmount, 2);

        $order->update([
            'total_amount' => $newTotal,
            'discount_amount' => $newDiscountTotal,
        ]);

        $shop->auditLogs()->create([
            'user_id'    => $request->user()->id,
            'action'     => 'discount_applied',
            'model_type' => \App\Models\CatalogOrder::class,
            'model_id'   => $order->id,
            'payload'    => [
                'amount' => $discountAmount,
                'reason' => $validated['reason'] ?? null,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Discount applied successfully.',
            'data'    => $order->load(['catalogItem', 'customer', 'branch:id,name']),
        ]);
    }

    public function verifyPayment(Request $request, $shopId, $orderId)
    {
        $this->authorizeShop($shopId);

        $order = \App\Models\CatalogOrder::where('shop_id', $shopId)->findOrFail($orderId);

        $validated = $request->validate([
            'payment_status' => 'required|in:pending,paid,rejected',
        ]);

        $oldPaymentStatus = $order->payment_status;

        $order->update([
            'payment_status' => $validated['payment_status']
        ]);

        // Previously silent either way — same gap as the appointment
        // payment path, just for walk-in/RTW Catalog Orders.
        if (in_array($validated['payment_status'], ['paid', 'rejected'], true) && $validated['payment_status'] !== $oldPaymentStatus) {
            $order->customer?->notify(new \App\Notifications\CatalogOrderPaymentStatusNotification($order, $validated['payment_status']));
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data'    => $order->load(['catalogItem', 'customer', 'branch:id,name']),
        ]);
    }
}
