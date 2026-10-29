<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\CustomApparelType;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Support\CanonicalTaxonomy;
use App\Support\DesignPricing;
use App\Support\SearchIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    /**
     * Is the authenticated user (if any) actually this specific store's
     * owner or staff? Mirrors CheckRole middleware's own ownership check —
     * but that middleware only guards the role-protected route path, not
     * the deliberately-public `/catalog/{store:slug}` one both index() and
     * show() are also reachable through, so the controller needs its own
     * copy of the same logic rather than trusting the route it happened to
     * be reached by.
     */
    /**
     * "Validate apparel categories": a garment_type off the fixed taxonomy is a
     * typed-in name. It links to its CustomApparelType (created pending on first
     * use) so the design stays off the storefront until the System Admin
     * approves the name. A name the admin already refused can't be reused.
     */
    private function customApparelTypeId(?string $garmentType, Store $store): ?int
    {
        if ($garmentType === null || trim($garmentType) === '' || in_array($garmentType, CatalogItem::garmentCategories(), true)) {
            return null;
        }

        $type = CustomApparelType::resolveFor($garmentType, $store);
        if ($type->status === 'rejected') {
            throw ValidationException::withMessages([
                'garment_type' => ['The apparel name "'.$type->label.'" was not approved by SUTURA'.($type->rejection_reason ? ': '.$type->rejection_reason : '.')],
            ]);
        }

        return $type->id;
    }

    /** A garment from the list is saved under its current slug; a typed name stays as typed (trimmed). */
    private function withCanonicalGarment(array $validated): array
    {
        if (! empty($validated['garment_type'])) {
            $validated['garment_type'] = CanonicalTaxonomy::canonicalGarment($validated['garment_type']) ?? trim($validated['garment_type']);
        }

        return $validated;
    }

    private function belongsToStore(Request $request, Store $store): bool
    {
        $user = $request->user('sanctum');
        if (! $user) {
            return false;
        }

        return $user->hasRole('store_owner')
            ? $store->owner_id === $user->id
            : $user->staffProfile?->store_id === $store->id;
    }

    /**
     * Display a listing of the resource.
     * Publicly accessible for customer viewing.
     */
    public function index(Request $request, Store $store): JsonResponse
    {
        $query = $store->catalogItems()
            ->with(['images', 'recommendations.recommendedItem', 'service:id,name', 'customApparelType'])
            ->withCount(['saves', 'reviews', 'catalogOrders', 'jobOrders'])
            ->withAvg('reviews', 'rating')
            ->withSum('catalogOrders as catalog_revenue', 'total_amount')
            // JobOrder discounts reduce balance directly, not total_amount,
            // and an unpaid/partial job hasn't generated this revenue yet —
            // same fix as CatalogController@show and AnalyticsController's
            // total_revenue. withSum only aggregates one column at a time,
            // so pull balance/discount separately and net them out below.
            ->withSum('jobOrders as job_revenue', 'total_amount')
            ->withSum('jobOrders as job_balance_sum', 'balance')
            ->withSum('jobOrders as job_discount_sum', 'discount_amount');

        // Anonymous (public storefront) visitors, AND any authenticated user
        // who isn't this specific store's owner/staff, only ever see active
        // items. This route is reachable both through the role-protected
        // `/stores/{store}/catalog` path and a second, deliberately public
        // `/catalog/{store:slug}` path — a real cross-tenant bug lived here
        // for a while: being logged in as *any* store owner was enough to
        // see paused items and private performance metrics (views/saves/
        // revenue) for a store that isn't yours, because the check only
        // asked "is there a token at all", not "does this token's owner
        // actually belong to this store". Explicit 'sanctum' guard: the
        // app's default guard is 'web' (session), which never resolves a
        // Bearer-token request — $request->user() alone would always read
        // as a guest here.
        $isOwnerOrStaff = $this->belongsToStore($request, $store);
        if (! $isOwnerOrStaff) {
            $query->listed();
        }

        if ($request->filled('search')) {
            // MySQL's default collation makes LIKE case-insensitive; Postgres's
            // LIKE never is. LOWER() on both sides works identically on both
            // engines, so search behaves the same after the Postgres migration.
            $search = strtolower((string) $request->string('search'));
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%']);
        }

        if ($request->filled('garment_type')) {
            $query->ofGarment((string) $request->string('garment_type'));
        }

        match ($request->string('sort')->toString()) {
            'price_desc' => $query->orderByDesc('price'),
            'price_asc' => $query->orderBy('price'),
            default => $query->latest(),
        };

        $items = $query->get();
        DesignPricing::attach($items);

        // Format the average rating nicely and attach dynamic sales performance metrics.
        // catalog_revenue/job_revenue come from withSum() above (one query for all
        // items) rather than a per-item ->sum() call, which used to run 2 extra
        // queries per item (96 extra queries for a 48-item catalog).
        $items->each(function ($item) use ($isOwnerOrStaff) {
            // No reviews yet → stays null ("no rating"), not round(null) = 0.
            $item->reviews_avg_rating = $item->reviews_avg_rating !== null ? round((float) $item->reviews_avg_rating, 1) : null;
            $netJobRevenue = (float) $item->job_revenue - (float) $item->job_balance_sum - (float) $item->job_discount_sum;
            $item->total_revenue = (float) $item->catalog_revenue + $netJobRevenue;
            $item->order_count = $item->catalog_orders_count + $item->job_orders_count;

            $item->saves_count = (int) ($item->saves_count ?? 0);
            $item->order_count = (int) (($item->catalog_orders_count ?? 0) + ($item->job_orders_count ?? 0));

            // Revenue and view counts remain owner/staff only; order_count and saves_count
            // are public engagement indicators displayed on the catalog card.
            if (! $isOwnerOrStaff) {
                $item->makeHidden([
                    'views_count', 'catalog_orders_count', 'job_orders_count',
                    'catalog_revenue', 'job_revenue', 'job_balance_sum', 'job_discount_sum',
                    'total_revenue',
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'store' => ['name' => $store->name, 'slug' => $store->slug, 'description' => $store->description, 'logo_path' => $store->logo_path],
        ]);
    }

    /**
     * Cross-store catalog showroom feed for the public landing page — index()
     * above is always scoped to one store; this pulls active items across
     * every approved, non-hidden store for the homepage's catalog grid.
     */
    /**
     * Km from the customer to the nearest ACTIVE branch of the shop that owns the row. One expression
     * reused for the select, the radius filter and the sort: Postgres (unlike MySQL) does not let WHERE /
     * HAVING / an ORDER BY expression refer to a SELECT alias. The cosine is clamped to [-1, 1] because
     * Postgres raises an error for ACOS() of a value that rounding pushes just past 1.
     */
    private static function distanceSql(string $shopIdColumn): string
    {
        return '(SELECT MIN(6371 * ACOS(LEAST(1.0, GREATEST(-1.0, '
            .'COS(RADIANS(?)) * COS(RADIANS(store_branches.latitude)) * COS(RADIANS(store_branches.longitude) - RADIANS(?)) '
            .'+ SIN(RADIANS(?)) * SIN(RADIANS(store_branches.latitude)))))) '
            .'FROM store_branches WHERE store_branches.store_id = '.$shopIdColumn." AND store_branches.status = 'active' AND store_branches.verification_status = 'verified')";
    }

    public function publicShowroom(Request $request): JsonResponse
    {
        $query = CatalogItem::query()
            ->listed()
            ->whereHas('store', fn ($q) => $q->where('is_hidden', false)->where('status', 'approved'))
            ->with([
                'store' => fn ($q) => $q->select('id', 'name', 'slug')->with([
                    'branches' => fn ($bq) => $bq->select('id', 'store_id', 'name', 'is_main', 'district', 'city', 'latitude', 'longitude')->live(),
                ]),
                // Not every seeded item has an image flagged is_primary (a
                // data-entry gap, not a rule) -- CatalogController::index()'s
                // own frontend consumer (store/[store_id]/page.tsx) already
                // falls back to the first image when none is primary; do the
                // same here instead of silently showing no image at all.
                'service:id,name',
                'images',
            ])
            ->withCount(['reviews', 'saves', 'catalogOrders', 'jobOrders'])
            ->withAvg('reviews', 'rating');

        // A garment matches its old slugs too (CanonicalTaxonomy::LEGACY_GARMENT_SLUGS), so old links and
        // designs saved before the 2-level taxonomy still line up.
        if ($request->filled('garment_type')) {
            $query->ofGarment((string) $request->string('garment_type'));
        } elseif ($request->filled('category')) {
            $query->ofGarment((string) $request->string('category'));
        }

        // 2-level taxonomy (2026-10-14): a garment category = all its garments (old slugs included).
        if ($request->filled('garment_category')) {
            $garments = CanonicalTaxonomy::garmentsInCategory((string) $request->string('garment_category'));
            $garments === [] ? $query->whereRaw('1 = 0') : $query->ofGarments($garments);
        }

        // Scopes "More Like This" (and any other same-garment-type lookup)
        // to one store -- without this, catalog-item-detail's recommendation
        // rail pulled matching items from every store platform-wide, showing
        // a completely different shop's designs under "More Like This".
        if ($request->filled('store_id')) {
            $query->where('catalog_items.store_id', $request->integer('store_id'));
        }

        // Same reading of the search as the shop list (App\Support\SearchIntent). A design answers a search that
        // names a garment and no work, or "make" ("barong", "make a barong tagalog"); a search for altering or
        // customizing ("fix my pants") is answered by services, not designs.
        $intent = $request->filled('q') ? SearchIntent::parse((string) $request->string('q')) : null;
        if ($intent !== null && in_array($intent['intent'], [SearchIntent::ALTER, SearchIntent::CUSTOMIZE], true)) {
            $query->whereRaw('1 = 0');
        } elseif ($intent !== null && SearchIntent::designsAnswer($intent)) {
            $search = strtolower((string) $request->string('q'));
            $query->where(fn ($q) => $q->ofGarments($intent['garments'])->orWhereRaw('LOWER(name) LIKE ?', ['%'.$search.'%']));
        } elseif ($request->filled('q')) {
            $search = strtolower((string) $request->string('q'));
            $words = array_values(array_filter(explode(' ', $search)));
            $query->where(function ($q) use ($search, $words) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(garment_type) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(material) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$search.'%']);
                });
                if (count($words) > 1) {
                    $q->orWhere(function ($sub) use ($words) {
                        foreach ($words as $w) {
                            $sub->where(function ($wq) use ($w) {
                                $wq->whereRaw('LOWER(name) LIKE ?', ['%'.$w.'%'])
                                    ->orWhereRaw('LOWER(garment_type) LIKE ?', ['%'.$w.'%'])
                                    ->orWhereRaw('LOWER(material) LIKE ?', ['%'.$w.'%'])
                                    ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$w.'%']);
                            });
                        }
                    });
                }
                $q->orWhereHas('service', function ($sq) use ($search) {
                    $sq->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(category) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(service_type) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$search.'%']);
                });
            });
        }

        // Real column (catalog_items.color), supports multiple comma-separated colors
        // (e.g. "White,Ivory" or "Red,Crimson") matching with OR.
        if ($request->filled('color')) {
            $rawColor = (string) $request->string('color');
            $colors = array_values(array_filter(array_map('trim', explode(',', $rawColor))));
            if (!empty($colors)) {
                $query->where(function ($sub) use ($colors) {
                    foreach ($colors as $c) {
                        $sub->orWhereRaw('LOWER(color) LIKE ?', ['%'.strtolower($c).'%']);
                    }
                });
            }
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->float('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->float('max_price'));
        }
        if ($request->filled('min_rating')) {
            // Same AVG as withAvg() above, repeated: a select alias cannot be used in WHERE/HAVING on Postgres.
            $query->whereRaw('(SELECT AVG(r.rating) FROM catalog_item_reviews r WHERE r.catalog_item_id = catalog_items.id) >= ?', [$request->float('min_rating')]);
        }

        // Haversine distance from customer coords to the store's nearest active branch
        $distanceSql = null;
        $distanceBind = [];
        if ($request->filled('lat') && $request->filled('lng')) {
            $lat = $request->float('lat');
            $lng = $request->float('lng');
            $distanceSql = self::distanceSql('catalog_items.store_id');
            $distanceBind = [$lat, $lng, $lat];

            // No "catalog_items.*," prefix: withCount()/withAvg() above already add it implicitly, and
            // repeating it made paginate()'s COUNT subquery fail with "Duplicate column name 'id'".
            $query->selectRaw("($distanceSql) as distance_km", $distanceBind);

            if ($request->filled('radius_km')) {
                $query->whereRaw("$distanceSql <= ?", [...$distanceBind, $request->float('radius_km')]);
            }
        }

        // Mobile search's location bar (see PublicNav's search redesign) --
        // filters to stores with at least one branch in the selected Davao
        // City district. Nested dot-relation whereHas, same pattern used
        // elsewhere in this controller for store-scoped visibility checks.
        if ($place = StoreBranch::placeFilter($request)) {
            $query->whereHas('store.branches', $place);
        }

        match ($request->string('sort_by')->toString()) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            // No real relevance-scoring infra exists (no search-rank column,
            // no full-text index) -- "Top Sales" is the one sort here with
            // an honest signal to order by.
            'top_sales' => $query->orderByRaw('((SELECT COUNT(*) FROM catalog_orders co WHERE co.catalog_item_id = catalog_items.id) + (SELECT COUNT(*) FROM job_orders jo WHERE jo.catalog_item_id = catalog_items.id AND jo.deleted_at IS NULL)) DESC'),
            'distance' => $distanceSql
                ? $query->orderByRaw("$distanceSql IS NULL, $distanceSql ASC", [...$distanceBind, ...$distanceBind])
                : $query->latest(),
            default => $query->latest(),
        };

        $items = $query->paginate($request->input('per_page', 48));
        DesignPricing::attach($items->getCollection());

        // Same reviews_avg_rating string-from-AVG() issue as index() above and
        // the public store feed — round it per-item into a real float. `price`
        // isn't cast on the model either (decimal columns come back as
        // strings from PDO) — cast it here too rather than let a fresh
        // .toFixed()-style crash happen on the frontend again.
        $items->getCollection()->transform(function (CatalogItem $item) {
            $item->reviews_avg_rating = $item->reviews_avg_rating !== null
                ? round((float) $item->reviews_avg_rating, 1)
                : null;
            $item->price = $item->price !== null ? (float) $item->price : null;
            $item->order_count = (int) ($item->catalog_orders_count + $item->job_orders_count);
            $item->saves_count = (int) ($item->saves_count ?? 0);
            $item->distance_km = isset($item->distance_km) && $item->distance_km !== null
                ? round((float) $item->distance_km, 1)
                : null;
            $item->makeHidden(['catalog_orders_count', 'job_orders_count']);

            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Store $store): JsonResponse
    {
        // 2-level taxonomy (2026-10-14): a garment from the list (old slugs too — saved as the garment they
        // were merged into) or a typed-in name, which goes to the System Admin for approval (customApparelTypeId).
        $garmentTypeRule = ['nullable', 'string', 'max:100'];

        $validated = $request->validate([
            ...\App\Support\OrderRequirements::offeringRules(),
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0|required_if:pricing_mode,set',
            // Priced by its service unless the shop says otherwise (App\Support\DesignPricing).
            'pricing_mode' => ['nullable', Rule::in(DesignPricing::MODES)],
            'extra_charge' => 'nullable|numeric|min:0|max:9999999|required_if:pricing_mode,extra',
            'estimated_days' => 'nullable|integer|min:1',
            'estimated_days_max' => 'nullable|integer|min:1|gt:estimated_days',
            'material' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:1000',
            'fabric_image_url' => 'nullable|string|max:500',
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|max:50',
            'add_ons' => 'nullable|array|max:30',
            'add_ons.*.id' => 'nullable|string|max:50',
            'add_ons.*.name' => 'required|string|max:100',
            'add_ons.*.price' => 'required|numeric|min:0|max:999999.99',
            'description' => 'nullable|string',
            'garment_type' => $garmentTypeRule,
            'size_chart_image_url' => 'nullable|string|max:500',
            'size_chart_columns' => 'nullable|array',
            'size_chart_rows' => 'nullable|array',
            'measurement_guide' => 'nullable|string',
            'features' => 'nullable|array',
            'care_instructions' => 'nullable|string',
            'external_gallery_url' => 'nullable|url|max:500',
            'is_active' => 'nullable|boolean',
            'service_id' => [
                'nullable', 'integer',
                Rule::exists('services', 'id')->where('store_id', $store->id),
            ],
            'images' => 'nullable|array|max:10',
            'images.*.url' => 'required|string',
            'images.*.angle' => 'required|string',
            'images.*.is_primary' => 'required|boolean',
            'recommendations' => 'nullable|array',
            'recommendations.*.id' => [
                'required', 'integer',
                Rule::exists('catalog_items', 'id')->where('store_id', $store->id),
            ],
            'recommendations.*.type' => 'nullable|string',
        ]);

        $validated = $this->withCanonicalGarment($validated);

        $item = $store->catalogItems()->create([
            'custom_apparel_type_id' => $this->customApparelTypeId($validated['garment_type'] ?? null, $store),
            'name' => $validated['name'],
            'price' => $validated['price'] ?? 0,
            'pricing_mode' => $validated['pricing_mode'] ?? null,
            'extra_charge' => ($validated['pricing_mode'] ?? null) === DesignPricing::EXTRA ? $validated['extra_charge'] : null,
            'estimated_days' => $validated['estimated_days'] ?? 7,
            'estimated_days_max' => $validated['estimated_days_max'] ?? null,
            'service_id' => $validated['service_id'] ?? null,
            'material' => $validated['material'] ?? null,
            'color' => $validated['color'] ?? null,
            'fabric_image_url' => $validated['fabric_image_url'] ?? null,
            'sizes' => $validated['sizes'] ?? null,
            'add_ons' => $validated['add_ons'] ?? null,
            'description' => $validated['description'] ?? null,
            'garment_type' => $validated['garment_type'] ?? null,
            // Made-to-order only — no ready-to-wear inventory or rental stock,
            // the approved thesis frames this as a tailoring tracker, not a
            // retail/rental system.
            'listing_type' => 'made_to_order',
            'is_active' => $validated['is_active'] ?? true,
            'measurement_requirement' => $validated['measurement_requirement'] ?? null,
            'fitting_requirement' => $validated['fitting_requirement'] ?? null,
            'payment_policy' => $validated['payment_policy'] ?? null,
            'payment_policy_percent' => $validated['payment_policy_percent'] ?? null,
            'size_chart_image_url' => $validated['size_chart_image_url'] ?? null,
            'size_chart_columns' => $validated['size_chart_columns'] ?? null,
            'size_chart_rows' => $validated['size_chart_rows'] ?? null,
            'measurement_guide' => $validated['measurement_guide'] ?? null,
            'features' => $validated['features'] ?? null,
            'care_instructions' => $validated['care_instructions'] ?? null,
            'external_gallery_url' => $validated['external_gallery_url'] ?? null,
        ]);

        if (! empty($validated['images'])) {
            foreach ($validated['images'] as $image) {
                $item->images()->create([
                    'image_url' => $image['url'],
                    'view_angle' => $image['angle'],
                    'is_primary' => $image['is_primary'],
                ]);
            }
        }

        if (! empty($validated['recommendations'])) {
            foreach ($validated['recommendations'] as $rec) {
                $item->recommendations()->create([
                    'recommended_item_id' => $rec['id'],
                    'recommendation_type' => $rec['type'] ?? 'similar',
                ]);
            }
        }

        $item->load(['images', 'recommendations']);
        DesignPricing::attach([$item]);

        return response()->json(['success' => true, 'data' => $item], 201);
    }

    /**
     * Display the specified resource.
     * Publicly accessible.
     */
    public function show(Request $request, Store $store, CatalogItem $catalog): JsonResponse
    {
        if ($catalog->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        // Pausing an item (is_active = false) is supposed to hide it from the public
        // storefront entirely, but this only ever filtered the listing grid — a
        // direct/bookmarked/guessed link to the item still returned full details.
        // Same belongsToStore() guard as index(): this store's own owner/staff can
        // still view/preview a paused item; anonymous visitors AND any other
        // store's authenticated owner/staff are blocked, same as everyone else.
        // isListed() also keeps a design whose custom apparel name is still
        // waiting for (or was refused) admin approval off the public page.
        if (! $this->belongsToStore($request, $store) && ! $catalog->isListed()) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $relations = [
            'images',
            'recommendations.recommendedItem.images',
            'reviews' => fn ($q) => $q->with('user:id,name,profile_picture')->latest()->limit(50),
            // The item detail page's own "visit this store" card — same
            // rating shape StoreController computes (loadCount/loadAvg on
            // reviews), so it reads identically to the store's own profile.
            // operating_hours: the Find a Branch pins show the same open/closed dot as /map.
            'store:id,name,slug,logo_path,operating_hours',
            // The "Find" location sheet needs somewhere to pin on the map —
            // same branch fields PublicBookingController::getSettings()
            // already exposes for the /book page's own map.
            'store.branches' => fn ($q) => $q->live()->select('id', 'store_id', 'slug', 'name', 'is_main', 'address', 'barangay', 'city', 'latitude', 'longitude'),
            // Whether this item can be Bulk Ordered depends entirely on
            // whether its linked service is bulk_sublimation-typed — the
            // frontend needs service_types to decide, not just the id.
            // The service's price too: a design is priced by its service (it only changes for custom work or add-ons).
            'service:id,name,service_category,service_types,min_order_qty,roster_fields,base_price,sale_price,sale_starts_at,sale_ends_at',
            'service.pricing',
        ];

        if ($this->belongsToStore($request, $store)) {
            $relations['catalogOrders'] = fn ($q) => $q->with('customer:id,name,phone,email')->latest()->limit(50);
            $relations['jobOrders'] = fn ($q) => $q->with('customer:id,name,phone,email')->latest()->limit(50);
        }

        $catalog->load($relations);
        $catalog->loadCount(['saves', 'reviews', 'catalogOrders', 'jobOrders']);
        $catalog->loadAvg('reviews', 'rating');
        $catalog->reviews_avg_rating = $catalog->reviews_avg_rating !== null ? round((float) $catalog->reviews_avg_rating, 1) : null;

        if ($catalog->store) {
            $catalog->store->loadCount('reviews');
            $catalog->store->loadAvg('reviews', 'rating');
            $catalog->store->reviews_avg_rating = $catalog->store->reviews_avg_rating !== null
                ? round((float) $catalog->store->reviews_avg_rating, 1)
                : null;
            // Active items/services only — matches what a customer actually
            // finds browsing this store's storefront, not a raw row count
            // that'd include paused/hidden ones nobody can see.
            $catalog->store->loadCount([
                'catalogItems as catalog_items_count' => fn ($q) => $q->listed(),
                'services as services_count' => fn ($q) => $q->where('is_active', true),
            ]);
        }

        // Sum up total amounts from both walk-in catalog orders and Job Orders.
        // CatalogOrder discounts reduce total_amount directly, so catalogRev
        // is already net — but JobOrder discounts reduce balance instead
        // (see AnalyticsController's own total_revenue for the same fix),
        // and an unpaid/partial job hasn't actually generated this revenue
        // yet, so both must be subtracted here too — otherwise a discounted
        // or still-outstanding job order inflates a catalog item's own
        // reported earnings.
        $catalogRev = (float) $catalog->catalogOrders()->sum('total_amount');
        $jobRev = (float) $catalog->jobOrders()->sum('total_amount')
            - (float) $catalog->jobOrders()->sum('balance')
            - (float) $catalog->jobOrders()->sum('discount_amount');
        $catalog->total_revenue = $catalogRev + $jobRev;

        // Sum order counts
        $catalog->order_count = $catalog->catalog_orders_count + $catalog->job_orders_count;

        // Same public-vs-owner split as index() for exact revenue/component
        // breakdowns — but unlike the catalog card list, the detail page's
        // UI (CatalogHeroGallery's "Favorite (count)", CatalogProductInfo's
        // "N Sold") is built to show saves_count/order_count to everyone,
        // the same way a Shopee/Etsy listing shows public heart and sold
        // counts as trust signals. Only the owner/staff-only business data
        // (raw view count, revenue, and the catalog-vs-job order breakdown)
        // stays hidden from anonymous/non-owner visitors.
        if (! $this->belongsToStore($request, $store)) {
            $catalog->makeHidden([
                'views_count', 'catalog_orders_count', 'job_orders_count',
                'total_revenue',
            ]);
        }
        DesignPricing::attach([$catalog]);

        return response()->json(['success' => true, 'data' => $catalog]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Store $store, CatalogItem $catalog): JsonResponse
    {
        if ($catalog->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // 2-level taxonomy (2026-10-14): a garment from the list (old slugs too — saved as the garment they
        // were merged into) or a typed-in name, which goes to the System Admin for approval (customApparelTypeId).
        $garmentTypeRule = ['nullable', 'string', 'max:100'];

        $validated = $request->validate([
            ...\App\Support\OrderRequirements::offeringRules(),
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|nullable|numeric|min:0|required_if:pricing_mode,set',
            'pricing_mode' => ['sometimes', 'nullable', Rule::in(DesignPricing::MODES)],
            'extra_charge' => 'nullable|numeric|min:0|max:9999999|required_if:pricing_mode,extra',
            'estimated_days' => 'nullable|integer|min:1',
            'estimated_days_max' => 'nullable|integer|min:1|gt:estimated_days',
            'material' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:1000',
            'fabric_image_url' => 'nullable|string|max:500',
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|max:50',
            'add_ons' => 'nullable|array|max:30',
            'add_ons.*.id' => 'nullable|string|max:50',
            'add_ons.*.name' => 'required|string|max:100',
            'add_ons.*.price' => 'required|numeric|min:0|max:999999.99',
            'description' => 'nullable|string',
            'garment_type' => $garmentTypeRule,
            'size_chart_image_url' => 'nullable|string|max:500',
            'size_chart_columns' => 'nullable|array',
            'size_chart_rows' => 'nullable|array',
            'measurement_guide' => 'nullable|string',
            'features' => 'nullable|array',
            'care_instructions' => 'nullable|string',
            'external_gallery_url' => 'nullable|url|max:500',
            'is_active' => 'sometimes|boolean',
            'service_id' => [
                'nullable', 'integer',
                Rule::exists('services', 'id')->where('store_id', $store->id),
            ],
            'images' => 'nullable|array|max:10',
            'images.*.url' => 'required|string',
            'images.*.angle' => 'required|string',
            'images.*.is_primary' => 'required|boolean',
            'recommendations' => 'nullable|array',
            'recommendations.*.id' => [
                'required', 'integer',
                Rule::exists('catalog_items', 'id')->where('store_id', $store->id),
            ],
            'recommendations.*.type' => 'nullable|string',
        ]);

        // Same admin-takedown lock as StoreController::update. Silently held
        // rather than rejected: the edit form resends is_active on every save,
        // and the owner must still be able to save the fix (e.g. a replaced
        // image) — they just can't republish it; an admin reviews and unhides.
        if ($catalog->admin_hidden_at) {
            $validated['is_active'] = false;
        }

        $validated = $this->withCanonicalGarment($validated);

        $catalog->update([
            'custom_apparel_type_id' => array_key_exists('garment_type', $validated)
                ? $this->customApparelTypeId($validated['garment_type'], $store)
                : $catalog->custom_apparel_type_id,
            'name' => $validated['name'] ?? $catalog->name,
            'price' => $validated['price'] ?? $catalog->price,
            'pricing_mode' => array_key_exists('pricing_mode', $validated) ? $validated['pricing_mode'] : $catalog->pricing_mode,
            // Only an "extra" design carries an extra charge; switching away clears it.
            'extra_charge' => (array_key_exists('pricing_mode', $validated) ? $validated['pricing_mode'] : $catalog->pricing_mode) === DesignPricing::EXTRA
                ? ($validated['extra_charge'] ?? $catalog->extra_charge)
                : null,
            'estimated_days' => array_key_exists('estimated_days', $validated) ? $validated['estimated_days'] : $catalog->estimated_days,
            'estimated_days_max' => array_key_exists('estimated_days_max', $validated) ? $validated['estimated_days_max'] : $catalog->estimated_days_max,
            'is_active' => array_key_exists('is_active', $validated) ? $validated['is_active'] : $catalog->is_active,
            'service_id' => array_key_exists('service_id', $validated) ? $validated['service_id'] : $catalog->service_id,
            'color' => array_key_exists('color', $validated) ? $validated['color'] : $catalog->color,
            'fabric_image_url' => array_key_exists('fabric_image_url', $validated) ? $validated['fabric_image_url'] : $catalog->fabric_image_url,
            'sizes' => array_key_exists('sizes', $validated) ? $validated['sizes'] : $catalog->sizes,
            'add_ons' => array_key_exists('add_ons', $validated) ? $validated['add_ons'] : $catalog->add_ons,
            'material' => array_key_exists('material', $validated) ? $validated['material'] : $catalog->material,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $catalog->description,
            'garment_type' => array_key_exists('garment_type', $validated) ? $validated['garment_type'] : $catalog->garment_type,
            'size_chart_image_url' => array_key_exists('size_chart_image_url', $validated) ? $validated['size_chart_image_url'] : $catalog->size_chart_image_url,
            'size_chart_columns' => array_key_exists('size_chart_columns', $validated) ? $validated['size_chart_columns'] : $catalog->size_chart_columns,
            'size_chart_rows' => array_key_exists('size_chart_rows', $validated) ? $validated['size_chart_rows'] : $catalog->size_chart_rows,
            'measurement_guide' => array_key_exists('measurement_guide', $validated) ? $validated['measurement_guide'] : $catalog->measurement_guide,
            'features' => array_key_exists('features', $validated) ? $validated['features'] : $catalog->features,
            'care_instructions' => array_key_exists('care_instructions', $validated) ? $validated['care_instructions'] : $catalog->care_instructions,
            'external_gallery_url' => array_key_exists('external_gallery_url', $validated) ? $validated['external_gallery_url'] : $catalog->external_gallery_url,
            'measurement_requirement' => array_key_exists('measurement_requirement', $validated) ? $validated['measurement_requirement'] : $catalog->measurement_requirement,
            'fitting_requirement' => array_key_exists('fitting_requirement', $validated) ? $validated['fitting_requirement'] : $catalog->fitting_requirement,
            'payment_policy' => array_key_exists('payment_policy', $validated) ? $validated['payment_policy'] : $catalog->payment_policy,
            'payment_policy_percent' => array_key_exists('payment_policy_percent', $validated) ? $validated['payment_policy_percent'] : $catalog->payment_policy_percent,
        ]);

        if (isset($validated['images'])) {
            // Remove old images
            $catalog->images()->delete();
            // Add new images
            foreach ($validated['images'] as $image) {
                $catalog->images()->create([
                    'image_url' => $image['url'],
                    'view_angle' => $image['angle'],
                    'is_primary' => $image['is_primary'],
                ]);
            }
        }

        if (isset($validated['recommendations'])) {
            // Remove old recommendations
            $catalog->recommendations()->delete();
            // Add new recommendations
            foreach ($validated['recommendations'] as $rec) {
                $catalog->recommendations()->create([
                    'recommended_item_id' => $rec['id'],
                    'recommendation_type' => $rec['type'] ?? 'similar',
                ]);
            }
        }

        $fresh = $catalog->fresh(['images', 'recommendations']);
        DesignPricing::attach([$fresh]);

        return response()->json(['success' => true, 'data' => $fresh]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Store $store, CatalogItem $catalog): JsonResponse
    {
        if ($catalog->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Same accountability gap job_order_deleted/staff_removed/
        // service_deleted/branch_deleted already closed — and CatalogItem
        // has no SoftDeletes/restore() at all, so unlike those four this is
        // the *only* trace left once the item is gone.
        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'catalog_item_deleted',
            'model_type' => CatalogItem::class,
            'model_id' => $catalog->id,
            'payload' => ['name' => $catalog->name],
            'ip_address' => $request->ip(),
        ]);

        $catalog->delete();

        return response()->json(['success' => true]);
    }
}
