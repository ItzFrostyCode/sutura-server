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

    public function index($shopId)
    {
        $this->authorizeShop($shopId);

        $orders = \App\Models\CatalogOrder::with(['catalogItem.images', 'customer'])
            ->where('shop_id', $shopId)
            ->latest()
            ->get();

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
        $this->authorizeShop($shopId);

        $validated = $request->validate([
            'catalog_item_id' => [
                'required',
                Rule::exists('catalog_items', 'id')->where('shop_id', $shopId),
            ],
            'selected_size'  => 'nullable|string|max:50',
            'customer_id'    => 'nullable|exists:users,id',
            'total_amount'   => 'required|numeric|min:0',
            'payment_status' => 'required|in:pending,paid',
        ]);

        $validated['shop_id']           = $shopId;
        $validated['type']              = 'walkin';
        $validated['status']            = 'ready';
        $validated['fulfillment_type']  = 'pickup';

        $order = \App\Models\CatalogOrder::create($validated);

        // Notify shop owner of the new order (mirrors Job Orders/Appointments,
        // which already notify regardless of who — owner or staff — logged it).
        $shop = \App\Models\Shop::find($shopId);
        $shopOwner = $shop?->owner;
        if ($shopOwner) {
            $shopOwner->notify(new \App\Notifications\NewCatalogOrderNotification($order));
        }

        return response()->json(['data' => $order->load(['catalogItem', 'customer'])], 201);
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
        // moved (prepped, handed over, etc.) it must be handled through the normal
        // lifecycle instead, not silently erased.
        if ($validated['status'] === 'cancelled' && $order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only a pending order can be cancelled.',
            ], 422);
        }

        $order->update($validated);

        return response()->json(['data' => $order->load(['catalogItem', 'customer'])]);
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
            'data'    => $order->load(['catalogItem', 'customer']),
        ]);
    }

    public function verifyPayment(Request $request, $shopId, $orderId)
    {
        $this->authorizeShop($shopId);

        $order = \App\Models\CatalogOrder::where('shop_id', $shopId)->findOrFail($orderId);

        $validated = $request->validate([
            'payment_status' => 'required|in:pending,paid,rejected',
        ]);

        $order->update([
            'payment_status' => $validated['payment_status']
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment status updated.',
            'data'    => $order->load(['catalogItem', 'customer']),
        ]);
    }
}
