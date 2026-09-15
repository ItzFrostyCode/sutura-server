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

        // Not currently rendered by any owner-side page, but publicProfile()
        // computes these for anyone viewing the shop publicly — an owner's
        // own authenticated view of their own shop shouldn't return less
        // data about it than a random visitor gets. Found while comparing
        // storefront-preview vs owner-dashboard consistency.
        $shop->loadCount('reviews');
        $shop->loadAvg('reviews', 'rating');
        $shop->reviews_avg_rating = round($shop->reviews_avg_rating, 1);

        return response()->json([
            'success' => true,
            'data' => $shop->load(['owner', 'subscriptions.plan'])
        ]);
    }

    /**
     * Public shop discovery/search/map feed — the endpoint Objectives 3/4
     * (Shop Discovery + Map-Based Navigation) needed but never had. Unlike
     * publicProfile() below, this one also checks `status = 'approved'` —
     * publicProfile() only ever checked is_hidden, which technically leaves
     * a pending/unapproved shop publicly reachable by direct slug lookup.
     * A listing surface shouldn't repeat that gap.
     */
    public function publicIndex(Request $request): JsonResponse
    {
        $query = Shop::query()
            ->where('is_hidden', false)
            ->where('status', 'approved');

        if ($request->filled('q')) {
            $search = strtolower((string) $request->string('q'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . $search . '%'])
                    ->orWhereRaw('LOWER(specializations) LIKE ?', ['%' . $search . '%'])
                    ->orWhereHas('services', function ($sq) use ($search) {
                        $sq->whereRaw('LOWER(category) LIKE ?', ['%' . $search . '%']);
                    });
            });
        }

        if ($request->filled('specialization')) {
            $query->whereJsonContains('specializations', $request->string('specialization')->toString());
        }

        if ($request->filled('district')) {
            $district = $request->string('district')->toString();
            $query->whereHas('branches', function ($bq) use ($district) {
                $bq->where('district', $district)->where('status', 'active');
            });
        }

        if ($request->filled('min_price') || $request->filled('max_price')) {
            $query->whereHas('services', function ($sq) use ($request) {
                $sq->where('is_active', true);
                if ($request->filled('min_price')) {
                    $sq->where('base_price', '>=', $request->float('min_price'));
                }
                if ($request->filled('max_price')) {
                    $sq->where('base_price', '<=', $request->float('max_price'));
                }
            });
        }

        $query->withCount('reviews')->withAvg('reviews', 'rating');

        if ($request->filled('min_rating')) {
            $query->havingRaw('reviews_avg_rating >= ?', [$request->float('min_rating')]);
        }

        if ($request->filled('lat') && $request->filled('lng')) {
            $lat = $request->float('lat');
            $lng = $request->float('lng');

            // No geospatial package exists anywhere in this codebase — plain
            // Haversine formula against each shop's nearest active branch.
            // No "shops.*," prefix here — withCount()/withAvg() above already
            // add it implicitly; repeating it caused a "duplicate column
            // 'id'" error once paginate() wrapped this into a COUNT subquery.
            $query->selectRaw('(
                SELECT MIN(6371 * ACOS(
                    COS(RADIANS(?)) * COS(RADIANS(shop_branches.latitude)) *
                    COS(RADIANS(shop_branches.longitude) - RADIANS(?)) +
                    SIN(RADIANS(?)) * SIN(RADIANS(shop_branches.latitude))
                ))
                FROM shop_branches
                WHERE shop_branches.shop_id = shops.id AND shop_branches.status = \'active\'
            ) as distance_km', [$lat, $lng, $lat]);

            if ($request->filled('radius_km')) {
                $query->having('distance_km', '<=', $request->float('radius_km'));
            }
        }

        match ($request->string('sort_by')->toString()) {
            'rating' => $query->orderByDesc('reviews_avg_rating'),
            'distance' => $query->orderBy('distance_km'),
            'price_asc' => $query->orderBy(
                \App\Models\Service::selectRaw('MIN(base_price)')
                    ->whereColumn('shop_id', 'shops.id')
                    ->where('is_active', true)
            ),
            'newest' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('is_featured')->orderByDesc('created_at'),
        };

        $shops = $query->with([
            'branches' => fn ($q) => $q->where('status', 'active'),
            'services' => fn ($q) => $q->where('is_active', true),
        ])->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $shops->items(),
            'meta' => [
                'current_page' => $shops->currentPage(),
                'last_page' => $shops->lastPage(),
                'per_page' => $shops->perPage(),
                'total' => $shops->total(),
            ],
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
        // Column-restricted eager load: this is the public, unauthenticated
        // storefront — the frontend only ever reads id/name/email/profile_picture
        // off `owner` (see ShopProfile.owner in shop/[shop_id]/page.tsx), but an
        // unrestricted load() was shipping the owner's full User record —
        // phone, exact last_seen_at, bio/education/skills, deleted_at — to any
        // anonymous visitor. Every other public endpoint here (reviews, posts)
        // already minimizes user data the same way; this one was the outlier.
        $shop->load(['branches' => function ($query) {
            $query->where('status', 'active');
        }, 'owner:id,name,email,profile_picture']);

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
