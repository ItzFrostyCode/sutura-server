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
    public function index(Shop $shop): JsonResponse
    {
        $items = $shop->catalogItems()
            ->with(['images', 'recommendations.recommendedItem'])
            ->withCount(['saves', 'reviews', 'catalogOrders', 'jobOrders'])
            ->withAvg('reviews', 'rating')
            ->get();
            
        // Format the average rating nicely and attach dynamic sales performance metrics
        $items->each(function($item) {
            $item->reviews_avg_rating = round($item->reviews_avg_rating, 1);
            
            // Sum up total amounts from both Ready-to-Wear catalog orders and custom Job orders
            $catalogRev = (float) $item->catalogOrders()->sum('total_amount');
            $jobRev = (float) $item->jobOrders()->sum('total_amount');
            $item->total_revenue = $catalogRev + $jobRev;
            
            // Sum order counts
            $item->order_count = $item->catalog_orders_count + $item->job_orders_count;
        });

        return response()->json(['success' => true, 'data' => $items]);
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
            'rental_price' => $validated['rental_price'] ?? null,
            'rental_deposit' => $validated['rental_deposit'] ?? null,
            'material' => $validated['material'] ?? null,
            'color' => $validated['color'] ?? null,
            'fabric_image_url' => $validated['fabric_image_url'] ?? null,
            'sizes' => $validated['sizes'] ?? null,
            'description' => $validated['description'] ?? null,
            'garment_type' => $validated['garment_type'] ?? null,
            'listing_type' => $validated['listing_type'] ?? 'made_to_order',
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

        $catalog->load(['images', 'recommendations.recommendedItem.images']);
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
            'sale_price' => array_key_exists('sale_price', $validated) ? $validated['sale_price'] : $catalog->sale_price,
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
