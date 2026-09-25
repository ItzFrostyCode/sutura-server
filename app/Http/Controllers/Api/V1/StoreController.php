<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CreateStoreRequest;
use App\Http\Requests\Store\UpdateStoreRequest;
use App\Models\Service;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\StoreSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $stores = $request->user()->stores()->with('subscriptions.plan')->get();

        return response()->json([
            'success' => true,
            'data' => $stores,
        ]);
    }

    public function store(CreateStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['owner_id'] = $request->user()->id;
        $validated['slug'] = Str::slug($validated['name']).'-'.uniqid();

        $store = Store::create($validated);

        // Auto-assign the Premium plan as the default subscription for new stores.
        // In production this would be gated behind a real payment step.
        $premiumPlan = SubscriptionPlan::where('slug', 'premium')
            ->where('is_active', true)
            ->first();

        if ($premiumPlan) {
            StoreSubscription::create([
                'store_id' => $store->id,
                'plan_id' => $premiumPlan->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Store registered. Awaiting admin approval.',
            'data' => $store,
        ], 201);
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        if ($request->user()->id !== $store->owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this store.',
            ], 403);
        }

        // Not currently rendered by any owner-side page, but publicProfile()
        // computes these for anyone viewing the store publicly — an owner's
        // own authenticated view of their own store shouldn't return less
        // data about it than a random visitor gets. Found while comparing
        // storefront-preview vs owner-dashboard consistency.
        $store->loadCount('reviews');
        $store->loadAvg('reviews', 'rating');
        $store->reviews_avg_rating = round($store->reviews_avg_rating, 1);

        return response()->json([
            'success' => true,
            'data' => $store->load(['owner', 'subscriptions.plan']),
        ]);
    }

    /**
     * Public store discovery/search/map feed — the endpoint Objectives 3/4
     * (Store Discovery + Map-Based Navigation) needed but never had. Unlike
     * publicProfile() below, this one also checks `status = 'approved'` —
     * publicProfile() only ever checked is_hidden, which technically leaves
     * a pending/unapproved store publicly reachable by direct slug lookup.
     * A listing surface shouldn't repeat that gap.
     */
    public function publicIndex(Request $request): JsonResponse
    {
        $query = Store::query()
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
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(specializations) LIKE ?', ['%'.$search.'%'])
                    ->orWhereHas('services', function ($sq) use ($search) {
                        $sq->whereRaw('LOWER(category) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$search.'%']);
                    })
                    ->orWhereHas('catalogItems', function ($cq) use ($search) {
                        $cq->where('is_active', true)
                            ->where(function ($ccq) use ($search) {
                                $ccq->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                                    ->orWhereRaw('LOWER(garment_type) LIKE ?', ['%'.$search.'%'])
                                    ->orWhereRaw('LOWER(material) LIKE ?', ['%'.$search.'%'])
                                    ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$search.'%']);
                            });
                    });
            });
        }

        $spec = $request->input('specialization') ?: $request->input('category');
        if ($spec) {
            $query->where(function ($sq) use ($spec) {
                $sq->whereJsonContains('specializations', (string) $spec)
                    ->orWhereHas('catalogItems', function ($cq) use ($spec) {
                        $cq->where('is_active', true)
                            ->where(function ($ccq) use ($spec) {
                                $ccq->where('garment_type', (string) $spec)
                                    ->orWhereRaw('LOWER(name) LIKE ?', ['%'.strtolower((string) $spec).'%']);
                            });
                    })
                    ->orWhereHas('services', function ($svq) use ($spec) {
                        $svq->whereRaw('LOWER(category) LIKE ?', ['%'.strtolower((string) $spec).'%']);
                    });
            });
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
            // 1. Asia/Manila, not the app's UTC default — Store::getActiveSpecialHoursAttribute()
            //    already carries this exact warning; a bare now() here would
            //    put the day/time boundary up to 8 hours off from the
            //    store's actual local day during Manila's early-morning hours.
            // 2. A store can declare a store-wide special-hours override
            //    (holiday closure, or a special open/close time) via
            //    StoreSpecialHour — checking only the regular weekly
            //    operating_hours would show a specially-closed store as
            //    "open" (or vice versa) on exactly the dates an owner most
            //    needs this to be accurate.
            $day = strtolower(now('Asia/Manila')->format('l'));
            $time = now('Asia/Manila')->format('H:i');
            $today = now('Asia/Manila')->toDateString();

            // Most-recent store-wide (not branch-specific) special-hours row
            // covering today, if any — same "most recently created wins on
            // overlap" tiebreak as Store::getActiveSpecialHoursAttribute().
            $todaySpecial = \DB::table('store_special_hours')
                ->selectRaw('store_id, is_closed, special_open_time, special_close_time, ROW_NUMBER() OVER (PARTITION BY store_id ORDER BY created_at DESC) as rn')
                ->whereNull('store_branch_id')
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today);

            $query->leftJoinSub($todaySpecial, 'today_special', function ($join) {
                $join->on('today_special.store_id', '=', 'stores.id')->where('today_special.rn', '=', 1);
            })
                ->where(function ($q) use ($day, $time) {
                    $q->where(function ($qq) use ($day, $time) {
                        // No special-hours override today — fall back to the
                        // regular weekly schedule. MySQL's ->> operator is
                        // JSON_UNQUOTE(JSON_EXTRACT(...)) shorthand; is_open
                        // compares against the unquoted string since
                        // JSON_UNQUOTE always yields a string, even for a JSON
                        // boolean.
                        $qq->whereNull('today_special.store_id')
                            ->whereRaw("stores.operating_hours->>'$.\"{$day}\".is_open' = 'true'")
                            ->whereRaw("stores.operating_hours->>'$.\"{$day}\".open' <= ?", [$time])
                            ->whereRaw("stores.operating_hours->>'$.\"{$day}\".close' > ?", [$time]);
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
                            $ccq->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                                ->orWhereRaw('LOWER(garment_type) LIKE ?', ['%'.$search.'%'])
                                ->orWhereRaw('LOWER(material) LIKE ?', ['%'.$search.'%'])
                                ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$search.'%']);
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
            // Haversine formula against each store's nearest active branch.
            // No "stores.*," prefix here — withCount()/withAvg() above already
            // add it implicitly; repeating it caused a "duplicate column
            // 'id'" error once paginate() wrapped this into a COUNT subquery.
            $query->selectRaw('(
                SELECT MIN(6371 * ACOS(
                    COS(RADIANS(?)) * COS(RADIANS(store_branches.latitude)) *
                    COS(RADIANS(store_branches.longitude) - RADIANS(?)) +
                    SIN(RADIANS(?)) * SIN(RADIANS(store_branches.latitude))
                ))
                FROM store_branches
                WHERE store_branches.store_id = stores.id AND store_branches.status = \'active\'
            ) as distance_km', [$lat, $lng, $lat]);

            if ($request->filled('radius_km')) {
                $query->having('distance_km', '<=', $request->float('radius_km'));
            }
        }

        $sortBy = $request->string('sort_by')->toString();
        if (! $sortBy) {
            if ($request->filled('lat') && $request->filled('lng')) {
                // Scenario 1: Location provided -> show all stores nearest to customer
                $query->orderBy('distance_km');
            } else {
                // Scenario 1: No location set -> show premium stores first, then highest ratings & reviews count
                $query->orderByDesc('is_featured')
                    ->orderByDesc(
                        StoreSubscription::select('store_subscriptions.id')
                            ->join('subscription_plans', 'subscription_plans.id', '=', 'store_subscriptions.plan_id')
                            ->whereColumn('store_subscriptions.store_id', 'stores.id')
                            ->where('store_subscriptions.status', 'active')
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
                    Service::selectRaw('MIN(base_price)')
                        ->whereColumn('store_id', 'stores.id')
                        ->where('is_active', true)
                ),
                'newest' => $query->orderByDesc('created_at'),
                default => $query->orderByDesc('is_featured')->orderByDesc('created_at'),
            };
        }

        $stores = $query->with([
            'branches' => fn ($q) => $q->where('status', 'active'),
            'services' => fn ($q) => $q->where('is_active', true),
            'owner:id,name',
            'catalogItems' => function ($cq) use ($request) {
                $cq->where('is_active', true)->with('images');
                if ($request->filled('q')) {
                    $search = strtolower((string) $request->string('q'));
                    $cq->orderByRaw('CASE WHEN LOWER(name) LIKE ? OR LOWER(garment_type) LIKE ? OR LOWER(material) LIKE ? OR LOWER(description) LIKE ? OR EXISTS (SELECT 1 FROM services WHERE services.id = catalog_items.service_id AND (LOWER(services.name) LIKE ? OR LOWER(services.category) LIKE ? OR LOWER(services.service_type) LIKE ?)) THEN 0 ELSE 1 END', [
                        '%'.$search.'%',
                        '%'.$search.'%',
                        '%'.$search.'%',
                        '%'.$search.'%',
                        '%'.$search.'%',
                        '%'.$search.'%',
                        '%'.$search.'%',
                    ]);
                }
                $cq->latest()->take(10);
            },
        ])->paginate($request->input('per_page', 20));

        // withAvg() returns reviews_avg_rating as whatever PDO hands back for
        // AVG() (a numeric string, not a float) since it's not a real column
        // Eloquent can cast — publicProfile()/show() round() it per-model for
        // the same reason; do the same here across the paginated collection.
        $stores->getCollection()->transform(function ($store) {
            $store->reviews_avg_rating = $store->reviews_avg_rating !== null
                ? round((float) $store->reviews_avg_rating, 1)
                : null;

            return $store;
        });

        return response()->json([
            'success' => true,
            'data' => $stores->items(),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'last_page' => $stores->lastPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ],
        ]);
    }

    public function publicProfile(Store $store): JsonResponse
    {
        // This route intentionally carries no `auth:sanctum` middleware (it's
        // the public storefront, guests must be able to load it) but the
        // owner still needs to reach their own hidden store's profile to edit
        // it — resolving the guard manually here (instead of relying on
        // route middleware to populate $request->user()) lets a Bearer token
        // still identify the owner without forcing auth on everyone else.
        $viewer = auth('sanctum')->user();
        if ($store->is_hidden && (! $viewer || $viewer->id !== $store->owner_id)) {
            return response()->json(['success' => false, 'message' => 'Store not found'], 404);
        }

        $store->loadCount('reviews');
        $store->loadAvg('reviews', 'rating');
        $store->reviews_avg_rating = round($store->reviews_avg_rating, 1);

        if ($viewer) {
            $store->my_review = StoreReview::where('store_id', $store->id)
                ->where('user_id', $viewer->id)
                ->first(['id', 'rating', 'comment']);
        } else {
            $store->my_review = null;
        }

        // Column-restricted eager load: this is the public, unauthenticated
        // storefront — the frontend only ever reads id/name/email/profile_picture
        // off `owner` (see StoreProfile.owner in store/[store_id]/page.tsx), but an
        // unrestricted load() was shipping the owner's full User record —
        // phone, exact last_seen_at, bio/education/skills, deleted_at — to any
        // anonymous visitor. Every other public endpoint here (reviews, posts)
        // already minimizes user data the same way; this one was the outlier.
        $store->load(['branches' => function ($query) {
            $query->where('status', 'active');
        }, 'owner:id,name,email,profile_picture']);

        return response()->json([
            'success' => true,
            'data' => $store,
        ]);
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $validated = $request->validated();

        // "Featured Store Visibility (Top Placement)" is a real Premium-plan
        // perk per the seeded plan data (SubscriptionPlanSeeder) — it was
        // documented there but never actually enforced anywhere until now.
        // Checks the plan's own features list rather than hardcoding the
        // plan slug, so the entitlement stays correct if plan tiers/features
        // are ever restructured.
        if (($validated['is_featured'] ?? false) === true) {
            $planFeatures = $store->subscription?->plan?->features ?? [];
            if (! in_array('Featured Store Visibility (Top Placement)', $planFeatures, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Featured placement requires a subscription plan that includes it.',
                ], 422);
            }
        }

        $store->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Store updated successfully.',
            'data' => $store,
        ]);
    }

    public function destroy(Request $request, Store $store): JsonResponse
    {
        if ($request->user()->id !== $store->owner_id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this store.',
            ], 403);
        }

        $store->delete();

        return response()->json([
            'success' => true,
            'message' => 'Store deleted successfully.',
        ]);
    }
}
