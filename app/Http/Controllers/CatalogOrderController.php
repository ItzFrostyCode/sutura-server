<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        $orders = \App\Models\CatalogOrder::with(['catalogItem', 'customer'])
            ->where('shop_id', $shopId)
            ->latest()
            ->get();

        return response()->json(['data' => $orders]);
    }

    public function store(Request $request, $shopId)
    {
        $this->authorizeShop($shopId);

        $validated = $request->validate([
            'catalog_item_id' => 'required|exists:catalog,id',
            'customer_id'     => 'nullable|exists:users,id',
            'type'            => 'required|in:walkin,online',
            'total_amount'    => 'required|numeric',
            'delivery_address' => 'nullable|string',
            'payment_status'  => 'required|string',
        ]);

        $validated['shop_id'] = $shopId;
        $validated['status']  = $validated['type'] === 'walkin' ? 'ready' : 'pending';

        $order = \App\Models\CatalogOrder::create($validated);

        return response()->json(['data' => $order->load(['catalogItem', 'customer'])], 201);
    }

    public function update(Request $request, $shopId, $orderId)
    {
        $this->authorizeShop($shopId);

        $order = \App\Models\CatalogOrder::where('shop_id', $shopId)->findOrFail($orderId);

        $validated = $request->validate([
            'status'         => 'required|in:pending,ready,out_for_delivery,completed',
            'payment_status' => 'sometimes|in:pending,paid',
        ]);

        $order->update($validated);

        return response()->json(['data' => $order->load(['catalogItem', 'customer'])]);
    }
}
