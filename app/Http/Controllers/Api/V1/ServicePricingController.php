<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreServicePricingRequest;
use App\Models\Shop;
use App\Models\Service;
use App\Models\ServicePricing;
use Illuminate\Http\JsonResponse;

class ServicePricingController extends Controller
{
    public function index(Shop $shop, Service $service): JsonResponse
    {
        if ($service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $service->pricing()->with('apparelSpecialization')->get()
        ]);
    }

    public function store(StoreServicePricingRequest $request, Shop $shop, Service $service): JsonResponse
    {
        if ($service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $pricing = $service->pricing()->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $pricing
        ], 201);
    }

    public function destroy(Shop $shop, Service $service, ServicePricing $pricing): JsonResponse
    {
        if ($service->shop_id !== $shop->id || $pricing->service_id !== $service->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $pricing->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pricing item deleted.'
        ]);
    }
}
