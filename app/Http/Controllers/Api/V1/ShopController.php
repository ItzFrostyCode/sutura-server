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
    public function index(Request $request): JsonResponse
    {
        $shops = $request->user()->shops()->with('subscriptions.plan')->get();

        return response()->json([
            'success' => true,
            'data' => $shops,
        ]);
    }

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

    public function show(Request $request, Shop $shop): JsonResponse
    {
        if ($request->user()->id !== $shop->owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this shop.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $shop->load(['owner', 'subscriptions.plan'])
        ]);
    }

    public function publicProfile(Shop $shop): JsonResponse
    {
        // This route intentionally carries no `auth:sanctum` middleware (it's
        // the public storefront, guests must be able to load it) but the
        // owner still needs to reach their own hidden shop's profile to edit
        // it — resolving the guard manually here (instead of relying on
        // route middleware to populate $request->user()) lets a Bearer token
        // still identify the owner without forcing auth on everyone else.
        $viewer = auth('sanctum')->user();
        if ($shop->is_hidden && (!$viewer || $viewer->id !== $shop->owner_id)) {
            return response()->json(['success' => false, 'message' => 'Shop not found'], 404);
        }

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
        $validated = $request->validated();

        // "Featured Shop Visibility (Top Placement)" is a real Premium-plan
        // perk per the seeded plan data (SubscriptionPlanSeeder) — it was
        // documented there but never actually enforced anywhere until now.
        // Checks the plan's own features list rather than hardcoding the
        // plan slug, so the entitlement stays correct if plan tiers/features
        // are ever restructured.
        if (($validated['is_featured'] ?? false) === true) {
            $planFeatures = $shop->subscription?->plan?->features ?? [];
            if (!in_array('Featured Shop Visibility (Top Placement)', $planFeatures, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Featured placement requires a subscription plan that includes it.',
                ], 422);
            }
        }

        $shop->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Shop updated successfully.',
            'data' => $shop
        ]);
    }

    public function destroy(Request $request, Shop $shop): JsonResponse
    {
        if ($request->user()->id !== $shop->owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this shop.',
            ], 403);
        }

        $shop->delete();

        return response()->json([
            'success' => true,
            'message' => 'Shop deleted successfully.',
        ]);
    }
}
