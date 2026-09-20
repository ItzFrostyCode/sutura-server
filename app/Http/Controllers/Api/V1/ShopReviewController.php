<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopReview;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShopReviewController extends Controller
{
    public function store(Request $request, Shop $shop): JsonResponse
    {
        $user = $request->user();

        // Shop owners cannot review their own shop
        if ($shop->owner_id === $user->id || $shop->user_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Shop owners cannot review their own shop.',
            ], 403);
        }

        // Staff members of this shop cannot review their employer
        if ($user->hasRole('staff') || $user->hasRole('branch_manager')) {
            $staffShopId = $user->staffProfile?->shop_id;
            if ($staffShopId === $shop->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff members cannot review their own shop.',
                ], 403);
            }
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:0|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $userId = $request->user()->id;

        // If rating is 0, unrate / remove existing rating
        if (empty($validated['rating']) || (int) $validated['rating'] === 0) {
            ShopReview::where('shop_id', $shop->id)
                ->where('user_id', $userId)
                ->delete();

            $shop->loadCount('reviews');
            $shop->loadAvg('reviews', 'rating');

            return response()->json([
                'success' => true,
                'message' => 'Shop rating removed.',
                'data' => null,
                'reviews_count' => $shop->reviews_count,
                'reviews_avg_rating' => $shop->reviews_avg_rating !== null ? round((float) $shop->reviews_avg_rating, 1) : null,
            ]);
        }

        $review = ShopReview::updateOrCreate(
            ['shop_id' => $shop->id, 'user_id' => $userId],
            ['rating' => (int) $validated['rating'], 'comment' => $validated['comment'] ?? null]
        );

        $shop->loadCount('reviews');
        $shop->loadAvg('reviews', 'rating');

        return response()->json([
            'success' => true,
            'message' => 'Shop rating saved successfully.',
            'data' => $review,
            'reviews_count' => $shop->reviews_count,
            'reviews_avg_rating' => $shop->reviews_avg_rating !== null ? round((float) $shop->reviews_avg_rating, 1) : null,
        ]);
    }

    /**
     * Get the authenticated user's current rating/review for this shop
     */
    public function myRating(Request $request, Shop $shop): JsonResponse
    {
        $review = ShopReview::where('shop_id', $shop->id)
            ->where('user_id', $request->user()->id)
            ->first(['id', 'shop_id', 'user_id', 'rating', 'comment', 'created_at', 'updated_at']);

        return response()->json([
            'success' => true,
            'data' => $review,
        ]);
    }

    /**
     * Cross-shop "my ratings" for the Shops tab of the customer's Star
     * Ratings page — same pattern as CatalogInteractionController::myReviews.
     */
    public function myReviews(Request $request): JsonResponse
    {
        $reviews = ShopReview::where('user_id', $request->user()->id)
            ->with('shop:id,name,slug,logo_path,city')
            ->latest()
            ->get()
            ->filter(fn (ShopReview $r) => $r->shop !== null)
            ->map(fn (ShopReview $r) => [
                'rating' => $r->rating,
                'rated_at' => $r->updated_at,
                'id' => $r->shop->id,
                'name' => $r->shop->name,
                'slug' => $r->shop->slug,
                'logo_path' => $r->shop->logo_path,
                'city' => $r->shop->city,
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $reviews]);
    }

    public function index(Shop $shop, Request $request): JsonResponse
    {
        // View all reviews for a shop
        $query = $shop->reviews()->with('user:id,name,email');

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        if ($request->has('is_featured')) {
            $query->where('is_featured', filter_var($request->is_featured, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 15))
        ]);
    }

    /**
     * Public feed for the storefront's Reviews tab — same review content as
     * the owner's dashboard list, just without the management fields being
     * writable, and reachable without authentication.
     */
    public function publicIndex(Shop $shop, Request $request): JsonResponse
    {
        $query = $shop->reviews()->with('user:id,name');

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 10))
        ]);
    }

    public function update(Request $request, Shop $shop, ShopReview $review): JsonResponse
    {
        if ($review->shop_id !== $shop->id) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $validated = $request->validate([
            'reply' => 'nullable|string',
            'is_featured' => 'boolean'
        ]);

        $review->update($validated);

        return response()->json([
            'success' => true,
            'data' => $review->fresh('user:id,name,email')
        ]);
    }

    public function destroy(Request $request, Shop $shop, ShopReview $review): JsonResponse
    {
        if ($review->shop_id !== $shop->id) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $review->delete();

        return response()->json([
            'success' => true,
            'message' => 'Review deleted successfully'
        ]);
    }
}
