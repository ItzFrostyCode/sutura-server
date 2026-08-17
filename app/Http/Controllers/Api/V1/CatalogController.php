<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    /**
     * Is the authenticated user (if any) actually this specific shop's
     * owner or staff? Mirrors CheckRole middleware's own ownership check —
     * but that middleware only guards the role-protected route path, not
     * the deliberately-public `/catalog/{shop:slug}` one both index() and
     * show() are also reachable through, so the controller needs its own
     * copy of the same logic rather than trusting the route it happened to
     * be reached by.
     */
    private function belongsToShop(Request $request, Shop $shop): bool
    {
        $user = $request->user('sanctum');
        if (!$user) {
            return false;
        }

        return $user->hasRole('shop_owner')
            ? $shop->owner_id === $user->id
            : $user->staffProfile?->shop_id === $shop->id;
    }

    /**
     * Display a listing of the resource.
     * Publicly accessible for customer viewing.
     */
    public function index(Request $request, Shop $shop): JsonResponse
    {
        $query = $shop->catalogItems()
            ->with(['images', 'recommendations.recommendedItem'])
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
        // who isn't this specific shop's owner/staff, only ever see active
        // items. This route is reachable both through the role-protected
        // `/shops/{shop}/catalog` path and a second, deliberately public
        // `/catalog/{shop:slug}` path — a real cross-tenant bug lived here
        // for a while: being logged in as *any* shop owner was enough to
        // see paused items and private performance metrics (views/saves/
        // revenue) for a shop that isn't yours, because the check only
        // asked "is there a token at all", not "does this token's owner
        // actually belong to this shop". Explicit 'sanctum' guard: the
        // app's default guard is 'web' (session), which never resolves a
        // Bearer-token request — $request->user() alone would always read
        // as a guest here.
        $isOwnerOrStaff = $this->belongsToShop($request, $shop);
        if (!$isOwnerOrStaff) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            // MySQL's default collation makes LIKE case-insensitive; Postgres's
            // LIKE never is. LOWER() on both sides works identically on both
            // engines, so search behaves the same after the Postgres migration.
            $search = strtolower((string) $request->string('search'));
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . $search . '%']);
        }

        if ($request->filled('garment_type')) {
            $query->where('garment_type', $request->string('garment_type'));
        }

        match ($request->string('sort')->toString()) {
            'price_desc' => $query->orderByDesc('price'),
            'price_asc' => $query->orderBy('price'),
            default => $query->latest(),
        };

        $items = $query->get();

        // Format the average rating nicely and attach dynamic sales performance metrics.
        // catalog_revenue/job_revenue come from withSum() above (one query for all
        // items) rather than a per-item ->sum() call, which used to run 2 extra
        // queries per item (96 extra queries for a 48-item catalog).
        $items->each(function($item) use ($isOwnerOrStaff) {
            $item->reviews_avg_rating = round($item->reviews_avg_rating, 1);
            $netJobRevenue = (float) $item->job_revenue - (float) $item->job_balance_sum - (float) $item->job_discount_sum;
            $item->total_revenue = (float) $item->catalog_revenue + $netJobRevenue;
            $item->order_count = $item->catalog_orders_count + $item->job_orders_count;

            // Sales/performance figures are the shop owner's own business data —
            // exact revenue and order counts have no business being visible to an
            // anonymous storefront visitor (or a competitor). The public catalog
            // card only ever renders reviews_count/reviews_avg_rating, so those
            // stay; everything money- or count-related below is owner/staff-only.
            if (!$isOwnerOrStaff) {
                $item->makeHidden([
                    'views_count', 'saves_count', 'catalog_orders_count', 'job_orders_count',
                    'catalog_revenue', 'job_revenue', 'job_balance_sum', 'job_discount_sum',
                    'total_revenue', 'order_count',
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'shop' => ['name' => $shop->name, 'slug' => $shop->slug, 'description' => $shop->description, 'logo_path' => $shop->logo_path],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'estimated_days' => 'nullable|integer|min:1',
            'material' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:100',
            'fabric_image_url' => 'nullable|string|max:500',
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|max:50',
            'description' => 'nullable|string',
            'garment_type' => 'nullable|string|max:100',
            'size_chart_image_url' => 'nullable|string|max:500',
            'size_chart_columns' => 'nullable|array',
            'size_chart_rows' => 'nullable|array',
            'features' => 'nullable|array',
            'care_instructions' => 'nullable|string',
            'external_gallery_url' => 'nullable|url|max:500',
            'is_active' => 'nullable|boolean',
            'images' => 'nullable|array',
            'images.*.url' => 'required|string',
            'images.*.angle' => 'required|string',
            'images.*.is_primary' => 'required|boolean',
            'recommendations' => 'nullable|array',
            'recommendations.*.id' => [
                'required', 'integer',
                \Illuminate\Validation\Rule::exists('catalog_items', 'id')->where('shop_id', $shop->id),
            ],
            'recommendations.*.type' => 'nullable|string',
        ]);

        $item = $shop->catalogItems()->create([
            'name' => $validated['name'],
            'price' => $validated['price'] ?? 0,
            'estimated_days' => $validated['estimated_days'] ?? 7,
            'material' => $validated['material'] ?? null,
            'color' => $validated['color'] ?? null,
            'fabric_image_url' => $validated['fabric_image_url'] ?? null,
            'sizes' => $validated['sizes'] ?? null,
            'description' => $validated['description'] ?? null,
            'garment_type' => $validated['garment_type'] ?? null,
            // Made-to-order only — no ready-to-wear inventory or rental stock,
            // the approved thesis frames this as a tailoring tracker, not a
            // retail/rental system.
            'listing_type' => 'made_to_order',
            'is_active' => $validated['is_active'] ?? true,
            'size_chart_image_url' => $validated['size_chart_image_url'] ?? null,
            'size_chart_columns' => $validated['size_chart_columns'] ?? null,
            'size_chart_rows' => $validated['size_chart_rows'] ?? null,
            'features' => $validated['features'] ?? null,
            'care_instructions' => $validated['care_instructions'] ?? null,
            'external_gallery_url' => $validated['external_gallery_url'] ?? null,
        ]);

        if (!empty($validated['images'])) {
            foreach ($validated['images'] as $image) {
                $item->images()->create([
                    'image_url' => $image['url'],
                    'view_angle' => $image['angle'],
                    'is_primary' => $image['is_primary'],
                ]);
            }
        }

        if (!empty($validated['recommendations'])) {
            foreach ($validated['recommendations'] as $rec) {
                $item->recommendations()->create([
                    'recommended_item_id' => $rec['id'],
                    'recommendation_type' => $rec['type'] ?? 'similar',
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $item->load(['images', 'recommendations'])
        ], 201);
    }

    /**
     * Display the specified resource.
     * Publicly accessible.
     */
    public function show(Request $request, Shop $shop, CatalogItem $catalog): JsonResponse
    {
        if ($catalog->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        // Pausing an item (is_active = false) is supposed to hide it from the public
        // storefront entirely, but this only ever filtered the listing grid — a
        // direct/bookmarked/guessed link to the item still returned full details.
        // Same belongsToShop() guard as index(): this shop's own owner/staff can
        // still view/preview a paused item; anonymous visitors AND any other
        // shop's authenticated owner/staff are blocked, same as everyone else.
        if (!$this->belongsToShop($request, $shop) && !$catalog->is_active) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $relations = [
            'images',
            'recommendations.recommendedItem.images',
            'reviews' => fn ($q) => $q->with('user:id,name')->latest()->limit(50),
        ];

        if ($this->belongsToShop($request, $shop)) {
            $relations['catalogOrders'] = fn ($q) => $q->with('customer:id,name,phone,email')->latest()->limit(50);
            $relations['jobOrders'] = fn ($q) => $q->with('customer:id,name,phone,email')->latest()->limit(50);
        }

        $catalog->load($relations);
        $catalog->loadCount(['saves', 'reviews', 'catalogOrders', 'jobOrders']);
        $catalog->loadAvg('reviews', 'rating');
        $catalog->reviews_avg_rating = round($catalog->reviews_avg_rating, 1);

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

        // Same public-vs-owner split as index() — an anonymous storefront
        // visitor (or a direct link) should never see this item's exact
        // revenue/order/save figures, only the owner/staff previewing it.
        if (!$this->belongsToShop($request, $shop)) {
            $catalog->makeHidden([
                'views_count', 'saves_count', 'catalog_orders_count', 'job_orders_count',
                'total_revenue', 'order_count',
            ]);
        }

        return response()->json(['success' => true, 'data' => $catalog]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Shop $shop, CatalogItem $catalog): JsonResponse
    {
        if ($catalog->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'estimated_days' => 'nullable|integer|min:1',
            'material' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:100',
            'fabric_image_url' => 'nullable|string|max:500',
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|max:50',
            'description' => 'nullable|string',
            'garment_type' => 'nullable|string|max:100',
            'size_chart_image_url' => 'nullable|string|max:500',
            'size_chart_columns' => 'nullable|array',
            'size_chart_rows' => 'nullable|array',
            'features' => 'nullable|array',
            'care_instructions' => 'nullable|string',
            'external_gallery_url' => 'nullable|url|max:500',
            'is_active' => 'sometimes|boolean',
            'images' => 'nullable|array',
            'images.*.url' => 'required|string',
            'images.*.angle' => 'required|string',
            'images.*.is_primary' => 'required|boolean',
            'recommendations' => 'nullable|array',
            'recommendations.*.id' => [
                'required', 'integer',
                \Illuminate\Validation\Rule::exists('catalog_items', 'id')->where('shop_id', $shop->id),
            ],
            'recommendations.*.type' => 'nullable|string',
        ]);

        $catalog->update([
            'name' => $validated['name'] ?? $catalog->name,
            'price' => $validated['price'] ?? $catalog->price,
            'estimated_days' => array_key_exists('estimated_days', $validated) ? $validated['estimated_days'] : $catalog->estimated_days,
            'is_active' => array_key_exists('is_active', $validated) ? $validated['is_active'] : $catalog->is_active,
            'color' => array_key_exists('color', $validated) ? $validated['color'] : $catalog->color,
            'fabric_image_url' => array_key_exists('fabric_image_url', $validated) ? $validated['fabric_image_url'] : $catalog->fabric_image_url,
            'sizes' => array_key_exists('sizes', $validated) ? $validated['sizes'] : $catalog->sizes,
            'material' => array_key_exists('material', $validated) ? $validated['material'] : $catalog->material,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $catalog->description,
            'garment_type' => array_key_exists('garment_type', $validated) ? $validated['garment_type'] : $catalog->garment_type,
            'size_chart_image_url' => array_key_exists('size_chart_image_url', $validated) ? $validated['size_chart_image_url'] : $catalog->size_chart_image_url,
            'size_chart_columns' => array_key_exists('size_chart_columns', $validated) ? $validated['size_chart_columns'] : $catalog->size_chart_columns,
            'size_chart_rows' => array_key_exists('size_chart_rows', $validated) ? $validated['size_chart_rows'] : $catalog->size_chart_rows,
            'features' => array_key_exists('features', $validated) ? $validated['features'] : $catalog->features,
            'care_instructions' => array_key_exists('care_instructions', $validated) ? $validated['care_instructions'] : $catalog->care_instructions,
            'external_gallery_url' => array_key_exists('external_gallery_url', $validated) ? $validated['external_gallery_url'] : $catalog->external_gallery_url,
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

        return response()->json(['success' => true, 'data' => $catalog->fresh(['images', 'recommendations'])]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Shop $shop, CatalogItem $catalog): JsonResponse
    {
        if ($catalog->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Same accountability gap job_order_deleted/staff_removed/
        // service_deleted/branch_deleted already closed — and CatalogItem
        // has no SoftDeletes/restore() at all, so unlike those four this is
        // the *only* trace left once the item is gone.
        $shop->auditLogs()->create([
            'user_id'    => $request->user()->id,
            'action'     => 'catalog_item_deleted',
            'model_type' => CatalogItem::class,
            'model_id'   => $catalog->id,
            'payload'    => ['name' => $catalog->name],
            'ip_address' => $request->ip(),
        ]);

        $catalog->delete();

        return response()->json(['success' => true]);
    }
}
