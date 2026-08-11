<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\CatalogItemReview;
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

    /**
     * Owner-facing list of every review left on any of this shop's catalog
     * items — mirrors ShopReviewController::index, but scoped one level
     * down (per-item, not per-shop). Before this, a customer could rate/
     * comment on a specific Barong/gown design and the owner had no page
     * anywhere that surfaced it.
     */
    public function indexForShop(Shop $shop, Request $request): JsonResponse
    {
        $query = CatalogItemReview::whereHas('catalogItem', fn ($q) => $q->where('shop_id', $shop->id))
            ->with(['user:id,name,email', 'catalogItem:id,name']);

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 15))
        ]);
    }

    public function replyToReview(Request $request, Shop $shop, CatalogItemReview $review): JsonResponse
    {
        if ($review->catalogItem?->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $validated = $request->validate([
            'reply' => 'nullable|string|max:1000',
        ]);

        // Only notify on an actual new/changed non-empty reply — not on
        // every save of an unrelated field, and not when the reply is being
        // cleared back to blank.
        $isNewReply = !empty($validated['reply']) && $validated['reply'] !== $review->reply;

        $review->update($validated);

        if ($isNewReply && $review->user) {
            $review->user->notify(new \App\Notifications\CatalogItemReviewReplyNotification($review->fresh(['catalogItem.shop'])));
        }

        return response()->json([
            'success' => true,
            'data' => $review->fresh(['user:id,name,email', 'catalogItem:id,name'])
        ]);
    }

    public function destroyReview(Shop $shop, CatalogItemReview $review): JsonResponse
    {
        if ($review->catalogItem?->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $review->delete();

        return response()->json(['success' => true, 'message' => 'Review deleted successfully']);
    }
}
