<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Star-only rating for a Service — no comment field exists on the
 * underlying table (see create_service_reviews_table migration), unlike
 * StoreReview/CatalogItemReview which both carry a legacy `comment` column.
 * Deliberately scoped small: no reply/moderation, no purchase-gating
 * (matches CatalogInteractionController::rate/StoreReviewController::store,
 * neither of which gate on a prior order today).
 */
class ServiceReviewController extends Controller
{
    private const NOT_FOUND_MESSAGE = 'Not found';

    public function rate(Request $request, Store $store, Service $service): JsonResponse
    {
        if ($service->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $validated = $request->validate([
            // 0 = unrate, same convention as StoreReviewController::store —
            // the shared RatingModal component lets a customer tap the same
            // star again to clear their rating, which submits 0.
            'rating' => 'required|integer|min:0|max:5',
        ]);

        $user = $request->user();

        if ((int) $validated['rating'] === 0) {
            $service->reviews()->where('user_id', $user->id)->delete();

            $service->loadCount('reviews');
            $service->loadAvg('reviews', 'rating');

            return response()->json([
                'success' => true,
                'message' => 'Service rating removed.',
                'review' => null,
                'reviews_count' => $service->reviews_count,
                'reviews_avg_rating' => $service->reviews_avg_rating !== null ? round((float) $service->reviews_avg_rating, 1) : null,
            ]);
        }

        $review = $service->reviews()->updateOrCreate(
            ['user_id' => $user->id],
            ['rating' => $validated['rating']]
        );

        $service->loadCount('reviews');
        $service->loadAvg('reviews', 'rating');

        return response()->json([
            'success' => true,
            'reviews_count' => $service->reviews_count,
            'reviews_avg_rating' => $service->reviews_avg_rating !== null ? round((float) $service->reviews_avg_rating, 1) : null,
            'review' => $review,
        ]);
    }

    /**
     * The authenticated user's current rating for this service, to pre-fill
     * a star picker if they've already rated it.
     */
    public function myRating(Request $request, Store $store, Service $service): JsonResponse
    {
        if ($service->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $review = $service->reviews()
            ->where('user_id', $request->user()->id)
            ->first(['id', 'service_id', 'user_id', 'rating']);

        return response()->json(['success' => true, 'data' => $review]);
    }
}
