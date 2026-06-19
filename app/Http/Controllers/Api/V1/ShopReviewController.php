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
}
