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
            ->withSum('jobOrders as job_revenue', 'total_amount');

        // Anonymous (public storefront) visitors only ever see active items;
        // the authenticated owner still sees/manages paused ones from the same endpoint.
        // Explicit 'sanctum' guard: this endpoint is reachable both with and without a
        // token, and the app's default guard is 'web' (session), which never resolves
        // a Bearer-token request — $request->user() alone would always read as a guest.
        if (!$request->user('sanctum')) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->string('search') . '%');
        }

        if ($request->filled('garment_type')) {
            $query->where('garment_type', $request->string('garment_type'));
        }

        $items = $query->get();

        // Format the average rating nicely and attach dynamic sales performance metrics.
        // catalog_revenue/job_revenue come from withSum() above (one query for all
        // items) rather than a per-item ->sum() call, which used to run 2 extra
        // queries per item (96 extra queries for a 48-item catalog).
        $items->each(function($item) {
            $item->reviews_avg_rating = round($item->reviews_avg_rating, 1);
            $item->total_revenue = (float) $item->catalog_revenue + (float) $item->job_revenue;
            $item->order_count = $item->catalog_orders_count + $item->job_orders_count;
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
            'price' => 'nullable|numeric',
            'sale_price' => 'nullable|numeric',
            'sale_starts_at' => 'nullable|date',
            'sale_ends_at' => 'nullable|date|after_or_equal:sale_starts_at',
            'rental_price' => 'nullable|numeric',
            'rental_deposit' => 'nullable|numeric',
            'material' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:100',
            'fabric_image_url' => 'nullable|string|max:500',
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|max:50',
            'description' => 'nullable|string',
            'garment_type' => 'nullable|string|max:100',
            'listing_type' => 'nullable|string|max:100',
            'fit_guide' => 'nullable|array',
            'features' => 'nullable|array',
            'care_instructions' => 'nullable|string',
            'external_gallery_url' => 'nullable|url|max:500',
            'is_active' => 'nullable|boolean',
            'images' => 'nullable|array',
            'images.*.url' => 'required|string',
            'images.*.angle' => 'required|string',
            'images.*.is_primary' => 'required|boolean',
            'recommendations' => 'nullable|array',
            'recommendations.*.id' => 'required|exists:catalog_items,id',
            'recommendations.*.type' => 'nullable|string',
        ]);

        $item = $shop->catalogItems()->create([
            'name' => $validated['name'],
            'price' => $validated['price'] ?? $validated['sale_price'] ?? 0,
            'sale_price' => $validated['sale_price'] ?? null,
            'sale_starts_at' => $validated['sale_starts_at'] ?? null,
            'sale_ends_at' => $validated['sale_ends_at'] ?? null,
            'rental_price' => $validated['rental_price'] ?? null,
            'rental_deposit' => $validated['rental_deposit'] ?? null,
            'material' => $validated['material'] ?? null,
            'color' => $validated['color'] ?? null,
            'fabric_image_url' => $validated['fabric_image_url'] ?? null,
            'sizes' => $validated['sizes'] ?? null,
            'description' => $validated['description'] ?? null,
            'garment_type' => $validated['garment_type'] ?? null,
            'listing_type' => $validated['listing_type'] ?? 'made_to_order',
            'is_active' => $validated['is_active'] ?? true,
            'fit_guide' => $validated['fit_guide'] ?? null,
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
    public function show(Shop $shop, CatalogItem $catalog): JsonResponse
    {
        if ($catalog->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $catalog->load([
            'images',
            'recommendations.recommendedItem.images',
            'reviews' => fn ($q) => $q->with('user:id,name')->latest()->limit(20),
        ]);
        $catalog->loadCount(['saves', 'reviews', 'catalogOrders', 'jobOrders']);
        $catalog->loadAvg('reviews', 'rating');
        $catalog->reviews_avg_rating = round($catalog->reviews_avg_rating, 1);

        // Sum up total amounts from both Ready-to-Wear catalog orders and custom Job orders
        $catalogRev = (float) $catalog->catalogOrders()->sum('total_amount');
        $jobRev = (float) $catalog->jobOrders()->sum('total_amount');
        $catalog->total_revenue = $catalogRev + $jobRev;
        
        // Sum order counts
        $catalog->order_count = $catalog->catalog_orders_count + $catalog->job_orders_count;

        return response()->json(['success' => true, 'data' => $catalog]);
    }

    /**
     * Publicly accessible, anonymized list of this item's currently-reserved
     * rental date ranges — no customer info, just the dates, so a shopper can
     * see genuinely booked ranges instead of a hardcoded placeholder.
     */
    public function rentalDates(Shop $shop, CatalogItem $catalog): JsonResponse
    {
        if ($catalog->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $dates = $catalog->catalogOrders()
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereNotNull('rental_start_date')
            ->whereNotNull('rental_end_date')
            ->orderBy('rental_start_date')
            ->get(['rental_start_date', 'rental_end_date'])
            ->map(fn ($order) => [
                'start' => $order->rental_start_date->toDateString(),
                'end'   => $order->rental_end_date->toDateString(),
            ]);

        return response()->json(['success' => true, 'data' => $dates]);
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
            'price' => 'sometimes|numeric',
            'sale_price' => 'nullable|numeric',
            'sale_starts_at' => 'nullable|date',
            'sale_ends_at' => 'nullable|date|after_or_equal:sale_starts_at',
            'rental_price' => 'nullable|numeric',
            'rental_deposit' => 'nullable|numeric',
            'material' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:100',
            'fabric_image_url' => 'nullable|string|max:500',
            'sizes' => 'nullable|array',
            'sizes.*' => 'string|max:50',
            'description' => 'nullable|string',
            'garment_type' => 'nullable|string|max:100',
            'listing_type' => 'nullable|string|max:100',
            'fit_guide' => 'nullable|array',
            'features' => 'nullable|array',
            'care_instructions' => 'nullable|string',
            'external_gallery_url' => 'nullable|url|max:500',
            'is_active' => 'sometimes|boolean',
            'images' => 'nullable|array',
            'images.*.url' => 'required|string',
            'images.*.angle' => 'required|string',
            'images.*.is_primary' => 'required|boolean',
            'recommendations' => 'nullable|array',
            'recommendations.*.id' => 'required|exists:catalog_items,id',
            'recommendations.*.type' => 'nullable|string',
        ]);

        $catalog->update([
            'name' => $validated['name'] ?? $catalog->name,
            'price' => $validated['price'] ?? $catalog->price,
            'is_active' => array_key_exists('is_active', $validated) ? $validated['is_active'] : $catalog->is_active,
            'sale_price' => array_key_exists('sale_price', $validated) ? $validated['sale_price'] : $catalog->sale_price,
            'sale_starts_at' => array_key_exists('sale_starts_at', $validated) ? $validated['sale_starts_at'] : $catalog->sale_starts_at,
            'sale_ends_at' => array_key_exists('sale_ends_at', $validated) ? $validated['sale_ends_at'] : $catalog->sale_ends_at,
            'rental_price' => array_key_exists('rental_price', $validated) ? $validated['rental_price'] : $catalog->rental_price,
            'rental_deposit' => array_key_exists('rental_deposit', $validated) ? $validated['rental_deposit'] : $catalog->rental_deposit,
            'color' => array_key_exists('color', $validated) ? $validated['color'] : $catalog->color,
            'fabric_image_url' => array_key_exists('fabric_image_url', $validated) ? $validated['fabric_image_url'] : $catalog->fabric_image_url,
            'sizes' => array_key_exists('sizes', $validated) ? $validated['sizes'] : $catalog->sizes,
            'material' => array_key_exists('material', $validated) ? $validated['material'] : $catalog->material,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $catalog->description,
            'garment_type' => array_key_exists('garment_type', $validated) ? $validated['garment_type'] : $catalog->garment_type,
            'listing_type' => array_key_exists('listing_type', $validated) ? $validated['listing_type'] : $catalog->listing_type,
            'fit_guide' => array_key_exists('fit_guide', $validated) ? $validated['fit_guide'] : $catalog->fit_guide,
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
    public function destroy(Shop $shop, CatalogItem $catalog): JsonResponse
    {
        if ($catalog->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $catalog->delete();

        return response()->json(['success' => true]);
    }
}
