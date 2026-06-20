<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreMeasurementRequest;
use App\Models\Shop;
use App\Models\Measurement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeasurementController extends Controller
{
    public function index(Shop $shop, Request $request): JsonResponse
    {
        $query = $shop->measurements()->with('customer:id,name,email');
        
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    public function store(StoreMeasurementRequest $request, Shop $shop): JsonResponse
    {
        $measurement = $shop->measurements()->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $measurement->load('customer:id,name')
        ], 201);
    }

    public function show(Shop $shop, Measurement $measurement): JsonResponse
    {
        if ($measurement->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $measurement->load('customer:id,name')
        ]);
    }

    public function update(Request $request, Shop $shop, Measurement $measurement): JsonResponse
    {
        if ($measurement->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Validate profile_name, metrics, and notes, supporting legacy measurements as well
        $validated = $request->validate([
            'customer_id' => 'sometimes|exists:users,id',
            'profile_name' => 'sometimes|string|max:100',
            'metrics' => 'sometimes|array',
            'measurements' => 'sometimes|array',
            'notes' => 'nullable|string'
        ]);

        if (isset($validated['measurements'])) {
            $validated['metrics'] = $validated['measurements'];
            unset($validated['measurements']);
        }

        $measurement->update($validated);

        return response()->json([
            'success' => true,
            'data' => $measurement->load('customer:id,name')
        ]);
    }

    public function destroy(Shop $shop, Measurement $measurement): JsonResponse
    {
        if ($measurement->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $measurement->delete();

        return response()->json([
            'success' => true,
            'message' => 'Measurement deleted successfully'
        ]);
    }
}
