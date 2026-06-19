<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreSpecializationRequest;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;

class SpecializationController extends Controller
{
    public function index(Shop $shop): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $shop->apparelSpecializations
        ]);
    }

    public function store(StoreSpecializationRequest $request, Shop $shop): JsonResponse
    {
        $specialization = $shop->apparelSpecializations()->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $specialization
        ], 201);
    }

    public function update(StoreSpecializationRequest $request, Shop $shop, $id): JsonResponse
    {
        $specialization = $shop->apparelSpecializations()->findOrFail($id);
        $specialization->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $specialization
        ]);
    }

    public function destroy(Shop $shop, $id): JsonResponse
    {
        $specialization = $shop->apparelSpecializations()->findOrFail($id);
        $specialization->delete();

        return response()->json([
            'success' => true,
            'message' => 'Specialization deleted successfully'
        ]);
    }
}
