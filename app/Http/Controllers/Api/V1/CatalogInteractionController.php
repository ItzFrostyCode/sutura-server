<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CatalogInteractionController extends Controller
{
    private const NOT_FOUND_MESSAGE = 'Not found';

    public function incrementViews(Shop $shop, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $catalogItem->increment('views_count');

        return response()->json(['success' => true, 'views_count' => $catalogItem->views_count]);
    }

    public function toggleSave(Request $request, Shop $shop, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $user = $request->user();
        
        $existingSave = $catalogItem->saves()->where('user_id', $user->id)->first();

        if ($existingSave) {
            $existingSave->delete();
            $status = 'unsaved';
        } else {
            $catalogItem->saves()->create(['user_id' => $user->id]);
            $status = 'saved';
        }

        return response()->json([
            'success' => true,
            'status' => $status,
            'saves_count' => $catalogItem->saves()->count()
        ]);
    }

    public function rate(Request $request, Shop $shop, CatalogItem $catalogItem): JsonResponse
    {
        if ($catalogItem->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string'
        ]);

        $user = $request->user();

        $review = $catalogItem->reviews()->updateOrCreate(
            ['user_id' => $user->id],
            ['rating' => $validated['rating'], 'comment' => $validated['comment']]
        );

        $averageRating = $catalogItem->reviews()->avg('rating');

        return response()->json([
            'success' => true,
            'average_rating' => round($averageRating, 1),
            'reviews_count' => $catalogItem->reviews()->count(),
            'review' => $review
        ]);
    }
}
