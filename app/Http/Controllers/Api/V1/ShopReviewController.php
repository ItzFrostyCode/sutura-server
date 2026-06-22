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
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $review = ShopReview::updateOrCreate(
            ['shop_id' => $shop->id, 'user_id' => $request->user()->id],
            ['rating' => $validated['rating'], 'comment' => $validated['comment']]
        );

        return response()->json([
            'success' => true,
            'message' => 'Shop rating submitted successfully.',
            'data' => $review
        ]);
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

    public function update(Request $request, Shop $shop, ShopReview $review): JsonResponse
    {
        // Authorize
        if ($request->user()->cannot('update', $shop) && !$request->user()->hasRole('shop_owner')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
        // Authorize
        if ($request->user()->cannot('delete', $shop) && !$request->user()->hasRole('shop_owner')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
