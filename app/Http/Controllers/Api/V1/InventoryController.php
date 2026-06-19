<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\InventoryItem;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Shop $shop)
    {
        return response()->json([
            'success' => true,
            'data' => $shop->inventoryItems()->with('supplier')->latest()->get()
        ]);
    }

    public function store(Request $request, Shop $shop)
    {
        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100',
            'category' => 'required|string|in:fabric,thread,accessory',
            'unit' => 'required|string|max:50',
            'current_stock' => 'numeric|min:0',
            'reorder_level' => 'numeric|min:0',
            'cost_per_unit' => 'numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $item = $shop->inventoryItems()->create($validated);

        if ($item->current_stock > 0) {
            $item->transactions()->create([
                'type' => 'in',
                'quantity' => $item->current_stock,
                'unit_cost' => $item->cost_per_unit,
                'notes' => 'Initial stock',
                'recorded_by' => $request->user()->id
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $item->load('supplier')
        ], 201);
    }

    public function show(Shop $shop, InventoryItem $inventory)
    {
        if ($inventory->shop_id !== $shop->id) abort(403);
        
        return response()->json([
            'success' => true,
            'data' => $inventory->load(['supplier', 'transactions.recordedBy', 'transactions.jobOrder'])
        ]);
    }

    public function update(Request $request, Shop $shop, InventoryItem $inventory)
    {
        if ($inventory->shop_id !== $shop->id) abort(403);

        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'name' => 'sometimes|required|string|max:255',
            'sku' => 'nullable|string|max:100',
            'category' => 'sometimes|required|string|in:fabric,thread,accessory',
            'unit' => 'sometimes|required|string|max:50',
            'reorder_level' => 'numeric|min:0',
            'cost_per_unit' => 'numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $inventory->update($validated);

        return response()->json([
            'success' => true,
            'data' => $inventory->load('supplier')
        ]);
    }

    public function destroy(Shop $shop, InventoryItem $inventory)
    {
        if ($inventory->shop_id !== $shop->id) abort(403);
        
        $inventory->delete();
        
        return response()->json(['success' => true]);
    }

    public function adjustStock(Request $request, Shop $shop, InventoryItem $inventory)
    {
        if ($inventory->shop_id !== $shop->id) abort(403);

        $validated = $request->validate([
            'type' => 'required|in:in,out,adjustment',
            'quantity' => 'required|numeric|min:0.01',
            'job_order_id' => 'nullable|exists:job_orders,id',
            'notes' => 'nullable|string'
        ]);

        $qty = $validated['quantity'];
        if ($validated['type'] === 'out' && $inventory->current_stock < $qty) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient stock'
            ], 400);
        }

        if ($validated['type'] === 'in') {
            $inventory->current_stock += $qty;
        } elseif ($validated['type'] === 'out') {
            $inventory->current_stock -= $qty;
        } else {
            $diff = $qty - $inventory->current_stock;
            $inventory->current_stock = $qty; 
            $qty = abs($diff);
        }
        
        $inventory->save();

        $inventory->transactions()->create([
            'job_order_id' => $validated['job_order_id'],
            'type' => $validated['type'],
            'quantity' => $qty,
            'unit_cost' => $inventory->cost_per_unit,
            'notes' => $validated['notes'],
            'recorded_by' => $request->user()->id
        ]);

        return response()->json([
            'success' => true,
            'data' => $inventory->load('supplier')
        ]);
    }
}
