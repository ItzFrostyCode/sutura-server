<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreServiceRequest;
use App\Models\Shop;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Shop $shop): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $shop->services()->with('pricing')->get()
        ]);
    }

    public function store(StoreServiceRequest $request, Shop $shop): JsonResponse
    {
        $service = $shop->services()->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $service
        ], 201);
    }

    public function update(Request $request, Shop $shop, Service $service): JsonResponse
    {
        if ($service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $service->update($request->all());

        return response()->json([
            'success' => true,
            'data' => $service
        ]);
    }

    public function destroy(Shop $shop, Service $service): JsonResponse
    {
        if ($service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service deleted.'
        ]);
    }
}
