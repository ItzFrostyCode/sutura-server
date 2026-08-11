<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopPostController extends Controller
{
    /**
     * Owner-side management list — includes every post regardless of age.
     */
    public function index(Shop $shop): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $shop->posts()->with('service:id,name')->latest()->get(),
        ]);
    }

    /**
     * Public feed for the shop's storefront page — same data, just reachable
     * without authentication.
     */
    public function publicIndex(Shop $shop): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $shop->posts()->with('service:id,name')->latest()->get(),
        ]);
    }

    public function store(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate([
            'image_urls' => ['required', 'array', 'min:1', 'max:12'],
            'image_urls.*' => ['string', 'max:2048'],
            'caption' => ['required', 'string', 'max:2000'],
            'service_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('services', 'id')->where('shop_id', $shop->id),
            ],
        ]);

        $post = $shop->posts()->create($validated);

        return response()->json(['success' => true, 'data' => $post->load('service:id,name')], 201);
    }

    public function update(Request $request, Shop $shop, ShopPost $post): JsonResponse
    {
        if ($post->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'image_urls' => ['sometimes', 'array', 'min:1', 'max:12'],
            'image_urls.*' => ['string', 'max:2048'],
            'caption' => ['sometimes', 'string', 'max:2000'],
            'service_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('services', 'id')->where('shop_id', $shop->id),
            ],
        ]);

        $post->update($validated);

        return response()->json(['success' => true, 'data' => $post->fresh('service:id,name')]);
    }

    public function destroy(Shop $shop, ShopPost $post): JsonResponse
    {
        if ($post->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $post->delete();

        return response()->json(['success' => true]);
    }
}
