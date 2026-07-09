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

    public function store(Request $request, $shopId)
    {
        $this->authorizeShop($shopId);

        $validated = $request->validate([
            'catalog_item_id' => [
                'required',
                Rule::exists('catalog_items', 'id')->where('shop_id', $shopId),
            ],
            'selected_size'           => 'nullable|string|max:50',
            'customer_id'             => 'nullable|exists:users,id',
            'type'                    => 'required|in:walkin,online',
            'total_amount'            => 'required|numeric|min:0',
            'delivery_address'        => 'nullable|string',
            'payment_status'          => 'required|in:pending,paid',
            'fulfillment_type'        => 'nullable|in:pickup,shipping,delivery',
            'rental_start_date'       => 'nullable|date',
            'rental_end_date'         => 'nullable|date|after_or_equal:rental_start_date',
            'security_deposit_amount' => 'nullable|numeric|min:0',
        ]);

        $catalogItem = \App\Models\CatalogItem::findOrFail($validated['catalog_item_id']);

        if ($catalogItem->listing_type === 'for_rent') {
            // Require pickup fulfillment for rentals
            $fulfillment = $validated['fulfillment_type'] ?? 'pickup';
            if ($fulfillment !== 'pickup') {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'fulfillment_type' => ['For Rent items must be Pickup at Shop only.']
                    ]
                ], 422);
            }
            // Require dates for rentals
            if (empty($validated['rental_start_date']) || empty($validated['rental_end_date'])) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'rental_start_date' => ['Pickup Date and Return Date are required for rentals.']
                    ]
                ], 422);
            }
            if ($catalogItem->hasRentalConflict($validated['rental_start_date'], $validated['rental_end_date'])) {
                return response()->json([
                    'message' => 'This item is already reserved for part of that date range.',
                    'errors'  => [
                        'rental_start_date' => ['This item is already reserved for part of that date range.']
                    ]
                ], 409);
            }
        }

        $validated['shop_id'] = $shopId;
        $validated['status']  = $validated['type'] === 'walkin' ? 'ready' : 'pending';
        $validated['fulfillment_type'] = $validated['fulfillment_type'] ?? 'pickup';

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

        $order = \App\Models\CatalogOrder::with('catalogItem')->where('shop_id', $shopId)->findOrFail($orderId);

        $validated = $request->validate([
            'status'                   => 'required|in:pending,ready,out_for_delivery,returned_pending_inspection,completed,cancelled',
            'payment_status'           => 'sometimes|in:pending,paid',
            'courier_name'             => 'nullable|string|max:255',
            'courier_tracking_number'  => 'nullable|string|max:255',
            'valid_id_captured'        => 'sometimes|boolean',
            'valid_id_notes'           => 'nullable|string|max:255',
            'return_inspection_notes'  => 'nullable|string|max:2000',
            'deposit_deduction_amount' => 'nullable|numeric|min:0',
        ]);

        // A cancelled order voids a mistaken/duplicate entry — once the item has actually
        // moved (prepped, shipped, returned, etc.) it must be handled through the normal
        // lifecycle instead, not silently erased.
        if ($validated['status'] === 'cancelled' && $order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only a pending order can be cancelled.',
            ], 422);
        }

        $isRental = $order->catalogItem?->listing_type === 'for_rent';

        // A deduction larger than the deposit actually held has no meaning —
        // there's nothing left to withhold beyond the deposit itself, and the
        // UI never separately computes/shows the refund amount, so this is the
        // only place a mistaken/miskeyed figure would ever get caught.
        if (isset($validated['deposit_deduction_amount']) && $order->security_deposit_amount !== null
            && (float) $validated['deposit_deduction_amount'] > (float) $order->security_deposit_amount) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => [
                    'deposit_deduction_amount' => ['Deduction cannot exceed the security deposit held (₱' . number_format((float) $order->security_deposit_amount, 2) . ').'],
                ],
            ], 422);
        }

        // Liability safeguard: a rental item cannot be handed over to the customer
        // without the shop first capturing/holding a valid government ID.
        if ($isRental && $validated['status'] === 'out_for_delivery') {
            $idCaptured = $validated['valid_id_captured'] ?? $order->valid_id_captured;
            if (!$idCaptured) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'valid_id_captured' => ['A valid government ID must be captured/held before releasing a rental item.'],
                    ],
                ], 422);
            }
        }

        $order->update($validated);

        return response()->json(['data' => $order->load(['catalogItem', 'customer'])]);
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
