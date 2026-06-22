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
            ->withCount(['saves', 'reviews'])
            ->withAvg('reviews', 'rating')
            ->get();
            
        // Format the average rating nicely
        $items->each(function($item) {
            $item->reviews_avg_rating = round($item->reviews_avg_rating, 1);
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
            'price' => 'required|numeric',
            'material' => 'nullable|string|max:255',
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
            'price' => $validated['price'],
            'material' => $validated['material'] ?? null,
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
        $catalog->loadCount(['saves', 'reviews']);
        $catalog->loadAvg('reviews', 'rating');
        $catalog->reviews_avg_rating = round($catalog->reviews_avg_rating, 1);

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
            'material' => 'nullable|string|max:255',
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
