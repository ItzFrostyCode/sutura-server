<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CatalogOrderController extends Controller
{
    public function index($shopId)
    {
        $orders = \App\Models\CatalogOrder::with(['catalogItem', 'customer'])
            ->where('shop_id', $shopId)
            ->latest()
            ->get();
            
        return response()->json(['data' => $orders]);
    }

    public function store(Request $request, $shopId)
    {
        $validated = $request->validate([
            'catalog_item_id' => 'required|exists:catalog,id',
            'customer_id' => 'nullable|exists:users,id',
            'type' => 'required|in:walkin,online',
            'total_amount' => 'required|numeric',
            'delivery_address' => 'nullable|string',
            'payment_status' => 'required|string',
        ]);

        $validated['shop_id'] = $shopId;
        $validated['status'] = $validated['type'] === 'walkin' ? 'ready' : 'pending';

        $order = \App\Models\CatalogOrder::create($validated);
        
        return response()->json(['data' => $order->load(['catalogItem', 'customer'])], 201);
    }

    public function update(Request $request, $shopId, $orderId)
    {
        $order = \App\Models\CatalogOrder::where('shop_id', $shopId)->findOrFail($orderId);
        
        $validated = $request->validate([
            'status' => 'required|in:pending,ready,out_for_delivery,completed',
            'payment_status' => 'sometimes|in:pending,paid'
        ]);

        $order->update($validated);

        return response()->json(['data' => $order->load(['catalogItem', 'customer'])]);
    }
}
