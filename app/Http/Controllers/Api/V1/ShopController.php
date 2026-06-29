<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreShopRequest;
use App\Http\Requests\Shop\UpdateShopRequest;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShopController extends Controller
{
    public function store(StoreShopRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['owner_id'] = $request->user()->id;
        $validated['slug'] = Str::slug($validated['name']) . '-' . uniqid();

        $shop = Shop::create($validated);

        // Auto-assign the Premium plan as the default subscription for new shops.
        // In production this would be gated behind a real payment step.
        $premiumPlan = \App\Models\SubscriptionPlan::where('slug', 'premium')
            ->where('is_active', true)
            ->first();

        if ($premiumPlan) {
            \App\Models\ShopSubscription::create([
                'shop_id'    => $shop->id,
                'plan_id'    => $premiumPlan->id,
                'status'     => 'active',
                'starts_at'  => now(),
                'ends_at'    => now()->addDays(30),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Shop registered. Awaiting admin approval.',
            'data'    => $shop
        ], 201);
    }

    public function show(Shop $shop): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $shop->load(['owner', 'subscriptions.plan'])
        ]);
    }

    public function publicProfile(Shop $shop): JsonResponse
    {
        $shop->loadCount('reviews');
        $shop->loadAvg('reviews', 'rating');
        $shop->reviews_avg_rating = round($shop->reviews_avg_rating, 1);
        $shop->load(['branches' => function ($query) {
            $query->where('status', 'active');
        }, 'owner']);

        return response()->json([
            'success' => true,
            'data' => $shop
        ]);
    }

    public function update(UpdateShopRequest $request, Shop $shop): JsonResponse
    {
        $shop->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Shop updated successfully.',
            'data' => $shop
        ]);
    }
}
