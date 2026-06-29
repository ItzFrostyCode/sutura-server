<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Shop $shop)
    {
        return response()->json([
            'success' => true,
            'data' => $shop->suppliers()->latest()->get()
        ]);
    }

    public function store(Request $request, Shop $shop)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $supplier = $shop->suppliers()->create($validated);

        return response()->json([
            'success' => true,
            'data' => $supplier
        ], 201);
    }

    public function show(Shop $shop, Supplier $supplier)
    {
        if ($supplier->shop_id !== $shop->id) {
            abort(403);
        }
        
        return response()->json([
            'success' => true,
            'data' => $supplier
        ]);
    }

    public function update(Request $request, Shop $shop, Supplier $supplier)
    {
        if ($supplier->shop_id !== $shop->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $supplier->update($validated);

        return response()->json([
            'success' => true,
            'data' => $supplier
        ]);
    }

    public function destroy(Shop $shop, Supplier $supplier)
    {
        if ($supplier->shop_id !== $shop->id) {
            abort(403);
        }
        
        $supplier->delete();
        
        return response()->json(['success' => true]);
    }
}
