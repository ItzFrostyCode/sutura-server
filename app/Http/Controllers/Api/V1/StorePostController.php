<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StorePostController extends Controller
{
    /**
     * Owner-side management list — includes every post regardless of age.
     */
    public function index(Store $store): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $store->posts()->with('service:id,name')->latest()->get(),
        ]);
    }

    /**
     * Public feed for the store's storefront page — same data, just reachable
     * without authentication.
     */
    public function publicIndex(Store $store): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $store->posts()->with('service:id,name')->latest()->get(),
        ]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'image_urls' => ['required', 'array', 'min:1', 'max:12'],
            'image_urls.*' => ['string', 'max:2048'],
            'caption' => ['required', 'string', 'max:2000'],
            'service_id' => [
                'nullable',
                Rule::exists('services', 'id')->where('store_id', $store->id),
            ],
        ]);

        $post = $store->posts()->create($validated);

        return response()->json(['success' => true, 'data' => $post->load('service:id,name')], 201);
    }

    public function update(Request $request, Store $store, StorePost $post): JsonResponse
    {
        if ($post->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'image_urls' => ['sometimes', 'array', 'min:1', 'max:12'],
            'image_urls.*' => ['string', 'max:2048'],
            'caption' => ['sometimes', 'string', 'max:2000'],
            'service_id' => [
                'nullable',
                Rule::exists('services', 'id')->where('store_id', $store->id),
            ],
        ]);

        $post->update($validated);

        return response()->json(['success' => true, 'data' => $post->fresh('service:id,name')]);
    }

    public function destroy(Store $store, StorePost $post): JsonResponse
    {
        if ($post->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $post->delete();

        return response()->json(['success' => true]);
    }
}
