<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServicePackageReviewController extends Controller
{
    private const NOT_FOUND_MESSAGE = 'Not found';

    public function publicIndex(Store $store, ServicePackage $servicePackage): JsonResponse
    {
        if ($servicePackage->store_id !== $store->id || ! $servicePackage->is_active) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $reviews = $servicePackage->reviews()
            ->with('user:id,name,profile_picture')
            ->latest()
            ->paginate(12);
        $ratingCounts = $servicePackage->reviews()
            ->selectRaw('rating, COUNT(*) AS total')
            ->groupBy('rating')
            ->pluck('total', 'rating')
            ->all();

        return response()->json([
            'success' => true,
            'data' => $reviews->items(),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'rating_counts' => $ratingCounts,
            ],
        ]);
    }

    public function rate(Request $request, Store $store, ServicePackage $servicePackage): JsonResponse
    {
        if ($servicePackage->store_id !== $store->id || ! $servicePackage->is_active) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:0|max:5',
        ]);

        if ((int) $validated['rating'] === 0) {
            $servicePackage->reviews()->where('user_id', $request->user()->id)->delete();
        } else {
            $servicePackage->reviews()->updateOrCreate(
                ['user_id' => $request->user()->id],
                ['rating' => $validated['rating']],
            );
        }

        $servicePackage->loadCount('reviews');
        $servicePackage->loadAvg('reviews', 'rating');

        return response()->json([
            'success' => true,
            'review' => $servicePackage->reviews()->where('user_id', $request->user()->id)->first(),
            'reviews_count' => $servicePackage->reviews_count,
            'reviews_avg_rating' => $servicePackage->reviews_avg_rating !== null
                ? round((float) $servicePackage->reviews_avg_rating, 1)
                : null,
        ]);
    }

    public function myRating(Request $request, Store $store, ServicePackage $servicePackage): JsonResponse
    {
        if ($servicePackage->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => self::NOT_FOUND_MESSAGE], 404);
        }

        $review = $servicePackage->reviews()
            ->where('user_id', $request->user()->id)
            ->first(['id', 'service_package_id', 'user_id', 'rating']);

        return response()->json(['success' => true, 'data' => $review]);
    }
}