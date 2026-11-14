<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreReviewController extends Controller
{
    public function store(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        // Store owners cannot review their own store
        if ($store->owner_id === $user->id || $store->user_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Store owners cannot review their own store.',
            ], 403);
        }

        // Staff members of this store cannot review their employer
        if ($user->hasRole('staff') || $user->hasRole('branch_manager')) {
            $staffStoreId = $user->staffProfile?->store_id;
            if ($staffStoreId === $store->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff members cannot review their own store.',
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
            StoreReview::where('store_id', $store->id)
                ->where('user_id', $userId)
                ->delete();

            $store->loadCount('reviews');
            $store->loadAvg('reviews', 'rating');

            return response()->json([
                'success' => true,
                'message' => 'Store rating removed.',
                'data' => null,
                'reviews_count' => $store->reviews_count,
                'reviews_avg_rating' => $store->reviews_avg_rating !== null ? round((float) $store->reviews_avg_rating, 1) : null,
            ]);
        }

        $review = StoreReview::updateOrCreate(
            ['store_id' => $store->id, 'user_id' => $userId],
            ['rating' => (int) $validated['rating'], 'comment' => $validated['comment'] ?? null]
        );

        $store->loadCount('reviews');
        $store->loadAvg('reviews', 'rating');

        return response()->json([
            'success' => true,
            'message' => 'Store rating saved successfully.',
            'data' => $review,
            'reviews_count' => $store->reviews_count,
            'reviews_avg_rating' => $store->reviews_avg_rating !== null ? round((float) $store->reviews_avg_rating, 1) : null,
        ]);
    }

    /**
     * Get the authenticated user's current rating/review for this store
     */
    public function myRating(Request $request, Store $store): JsonResponse
    {
        $review = StoreReview::where('store_id', $store->id)
            ->where('user_id', $request->user()->id)
            ->first(['id', 'store_id', 'user_id', 'rating', 'comment', 'created_at', 'updated_at']);

        return response()->json([
            'success' => true,
            'data' => $review,
        ]);
    }

    /**
     * Cross-store "my ratings" for the Stores tab of the customer's Star
     * Ratings page — same pattern as CatalogInteractionController::myReviews.
     */
    public function myReviews(Request $request): JsonResponse
    {
        $reviews = StoreReview::where('user_id', $request->user()->id)
            ->with([
                'store:id,name,slug,logo_path,banner_path,city,operating_hours',
                'store.branches:id,store_id,name,city',
            ])
            ->latest()
            ->get()
            ->filter(fn (StoreReview $r) => $r->store !== null)
            ->map(function (StoreReview $r) {
                $store = $r->store;
                $store->loadCount('reviews');
                $store->loadAvg('reviews', 'rating');

                return [
                    'rating' => $r->rating,
                    'rated_at' => $r->updated_at,
                    'id' => $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'logo_path' => $store->logo_path,
                    'banner_path' => $store->banner_path,
                    'city' => $store->city,
                    'operating_hours' => $store->operating_hours,
                    'reviews_count' => $store->reviews_count,
                    'reviews_avg_rating' => $store->reviews_avg_rating ? round($store->reviews_avg_rating, 1) : null,
                    'branches' => $store->branches->map(fn ($b) => ['name' => $b->name, 'city' => $b->city])->values(),
                ];
            })
            ->values();

        return response()->json(['success' => true, 'data' => $reviews]);
    }

    public function index(Store $store, Request $request): JsonResponse
    {
        // View all reviews for a store
        $query = $store->reviews()->with('user:id,name,email');

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        if ($request->has('is_featured')) {
            $query->where('is_featured', filter_var($request->is_featured, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 15)),
        ]);
    }

    /**
     * Public feed for the storefront's Reviews tab — same review content as
     * the owner's dashboard list, just without the management fields being
     * writable, and reachable without authentication.
     */
    public function publicIndex(Store $store, Request $request): JsonResponse
    {
        $query = $store->reviews()->with('user:id,name');

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 10)),
        ]);
    }

    public function update(Request $request, Store $store, StoreReview $review): JsonResponse
    {
        if ($review->store_id !== $store->id) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $validated = $request->validate([
            'reply' => 'nullable|string',
            'is_featured' => 'boolean',
        ]);

        $review->update($validated);

        return response()->json([
            'success' => true,
            'data' => $review->fresh('user:id,name,email'),
        ]);
    }

    public function destroy(Request $request, Store $store, StoreReview $review): JsonResponse
    {
        if ($review->store_id !== $store->id) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $review->delete();

        return response()->json([
            'success' => true,
            'message' => 'Review deleted successfully',
        ]);
    }
}
