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
            ->where('status', 'approved')
            // SUTURA's own scope is Davao City — a server-side guarantee,
            // not just a coincidence of the current seed data all happening
            // to be Davao-based, so a future out-of-scope registration
            // can't silently show up on the public discovery map/search.
            ->where(function ($q) {
                $q->where('city', 'like', '%Davao%')
                  ->orWhere('province', 'like', '%Davao del Sur%');
            });

        if ($request->filled('q')) {
            $search = strtolower((string) $request->string('q'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . $search . '%'])
                    ->orWhereRaw('LOWER(specializations) LIKE ?', ['%' . $search . '%'])
                    ->orWhereHas('services', function ($sq) use ($search) {
                        $sq->whereRaw('LOWER(category) LIKE ?', ['%' . $search . '%'])
                            ->orWhereRaw('LOWER(name) LIKE ?', ['%' . $search . '%']);
                    })
                    ->orWhereHas('catalogItems', function ($cq) use ($search) {
                        $cq->where('is_active', true)
                            ->where(function ($ccq) use ($search) {
                                $ccq->whereRaw('LOWER(name) LIKE ?', ['%' . $search . '%'])
                                    ->orWhereRaw('LOWER(garment_type) LIKE ?', ['%' . $search . '%'])
                                    ->orWhereRaw('LOWER(material) LIKE ?', ['%' . $search . '%'])
                                    ->orWhereRaw('LOWER(description) LIKE ?', ['%' . $search . '%']);
                            });
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

        if ($request->boolean('open_now')) {
            // Real-time branch availability, per Objective 3's own wording.
            // Two things this has to get right, both already-known failure
            // modes elsewhere in this codebase:
            // 1. Asia/Manila, not the app's UTC default — Shop::getActiveSpecialHoursAttribute()
            //    already carries this exact warning; a bare now() here would
            //    put the day/time boundary up to 8 hours off from the
            //    shop's actual local day during Manila's early-morning hours.
            // 2. A shop can declare a shop-wide special-hours override
            //    (holiday closure, or a special open/close time) via
            //    ShopSpecialHour — checking only the regular weekly
            //    operating_hours would show a specially-closed shop as
            //    "open" (or vice versa) on exactly the dates an owner most
            //    needs this to be accurate.
            $day = strtolower(now('Asia/Manila')->format('l'));
            $time = now('Asia/Manila')->format('H:i');
            $today = now('Asia/Manila')->toDateString();

            // Most-recent shop-wide (not branch-specific) special-hours row
            // covering today, if any — same "most recently created wins on
            // overlap" tiebreak as Shop::getActiveSpecialHoursAttribute().
            $todaySpecial = \DB::table('shop_special_hours')
                ->selectRaw('shop_id, is_closed, special_open_time, special_close_time, ROW_NUMBER() OVER (PARTITION BY shop_id ORDER BY created_at DESC) as rn')
                ->whereNull('shop_branch_id')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today);

            $query->leftJoinSub($todaySpecial, 'today_special', function ($join) {
                $join->on('today_special.shop_id', '=', 'shops.id')->where('today_special.rn', '=', 1);
            })
            ->where(function ($q) use ($day, $time) {
                $q->where(function ($qq) use ($day, $time) {
                    // No special-hours override today — fall back to the
                    // regular weekly schedule. MySQL's ->> operator is
                    // JSON_UNQUOTE(JSON_EXTRACT(...)) shorthand; is_open
                    // compares against the unquoted string since
                    // JSON_UNQUOTE always yields a string, even for a JSON
                    // boolean.
                    $qq->whereNull('today_special.shop_id')
                        ->whereRaw("shops.operating_hours->>'$.\"{$day}\".is_open' = 'true'")
                        ->whereRaw("shops.operating_hours->>'$.\"{$day}\".open' <= ?", [$time])
                        ->whereRaw("shops.operating_hours->>'$.\"{$day}\".close' > ?", [$time]);
                })->orWhere(function ($qq) use ($time) {
                    // Override exists today and it's not a full closure —
                    // check the special hours instead of the regular ones.
                    $qq->where('today_special.is_closed', false)
                        ->where('today_special.special_open_time', '<=', $time)
                        ->where('today_special.special_close_time', '>', $time);
                });
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

        if ($request->filled('q')) {
            $search = strtolower((string) $request->string('q'));
            $query->withCount([
                'catalogItems as matching_items_count' => function ($cq) use ($search) {
                    $cq->where('is_active', true)
                        ->where(function ($ccq) use ($search) {
                            $ccq->whereRaw('LOWER(name) LIKE ?', ['%' . $search . '%'])
                                ->orWhereRaw('LOWER(garment_type) LIKE ?', ['%' . $search . '%'])
                                ->orWhereRaw('LOWER(material) LIKE ?', ['%' . $search . '%'])
                                ->orWhereRaw('LOWER(description) LIKE ?', ['%' . $search . '%']);
                        });
                },
            ]);
        }

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

        $sortBy = $request->string('sort_by')->toString();
        if (!$sortBy) {
            if ($request->filled('lat') && $request->filled('lng')) {
                // Scenario 1: Location provided -> show all stores nearest to customer
                $query->orderBy('distance_km');
            } else {
                // Scenario 1: No location set -> show premium stores first, then highest ratings & reviews count
                $query->orderByDesc('is_featured')
                    ->orderByDesc(
                        \App\Models\ShopSubscription::select('shop_subscriptions.id')
                            ->join('subscription_plans', 'subscription_plans.id', '=', 'shop_subscriptions.plan_id')
                            ->whereColumn('shop_subscriptions.shop_id', 'shops.id')
                            ->where('shop_subscriptions.status', 'active')
                            ->where('subscription_plans.slug', 'premium')
                            ->limit(1)
                    )
                    ->orderByDesc('reviews_avg_rating')
                    ->orderByDesc('reviews_count')
                    ->orderByDesc('created_at');
            }
        } else {
            match ($sortBy) {
                'name_asc' => $query->orderBy('name'),
                'rating' => $query->orderByDesc('reviews_avg_rating')->orderByDesc('reviews_count'),
                'distance' => $query->orderBy('distance_km'),
                'price_asc' => $query->orderBy(
                    \App\Models\Service::selectRaw('MIN(base_price)')
                        ->whereColumn('shop_id', 'shops.id')
                        ->where('is_active', true)
                ),
                'newest' => $query->orderByDesc('created_at'),
                default => $query->orderByDesc('is_featured')->orderByDesc('created_at'),
            };
        }

        $shops = $query->with([
            'branches' => fn ($q) => $q->where('status', 'active'),
            'services' => fn ($q) => $q->where('is_active', true),
            'owner:id,name',
            'catalogItems' => function ($cq) use ($request) {
                $cq->where('is_active', true)->with('images');
                if ($request->filled('q')) {
                    $search = strtolower((string) $request->string('q'));
                    $cq->orderByRaw("CASE WHEN LOWER(name) LIKE ? OR LOWER(garment_type) LIKE ? OR LOWER(material) LIKE ? OR LOWER(description) LIKE ? OR EXISTS (SELECT 1 FROM services WHERE services.id = catalog_items.service_id AND (LOWER(services.name) LIKE ? OR LOWER(services.category) LIKE ? OR LOWER(services.service_type) LIKE ?)) THEN 0 ELSE 1 END", [
                        '%' . $search . '%',
                        '%' . $search . '%',
                        '%' . $search . '%',
                        '%' . $search . '%',
                        '%' . $search . '%',
                        '%' . $search . '%',
                        '%' . $search . '%',
                    ]);
                }
                $cq->latest()->take(10);
            },
        ])->paginate($request->input('per_page', 20));

        // withAvg() returns reviews_avg_rating as whatever PDO hands back for
        // AVG() (a numeric string, not a float) since it's not a real column
        // Eloquent can cast — publicProfile()/show() round() it per-model for
        // the same reason; do the same here across the paginated collection.
        $shops->getCollection()->transform(function ($shop) {
            $shop->reviews_avg_rating = $shop->reviews_avg_rating !== null
                ? round((float) $shop->reviews_avg_rating, 1)
                : null;
            return $shop;
        });

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

        if ($viewer) {
            $shop->my_review = \App\Models\ShopReview::where('shop_id', $shop->id)
                ->where('user_id', $viewer->id)
                ->first(['id', 'rating', 'comment']);
        } else {
            $shop->my_review = null;
        }

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
