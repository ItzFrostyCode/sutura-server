<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreSave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Save (heart) a shop, and the customer's own list of saved shops for My Saved > Shops.
 * Mirrors ServiceReviewController::toggleSave / mySave / mySavedServices. Customer-scoped, no role gate.
 */
class StoreSaveController extends Controller
{
    public function toggle(Request $request, Store $store): JsonResponse
    {
        $userId = $request->user()->id;
        $existing = StoreSave::where('store_id', $store->id)->where('user_id', $userId)->first();

        if ($existing) {
            $existing->delete();
            $status = 'unsaved';
        } else {
            StoreSave::create(['store_id' => $store->id, 'user_id' => $userId]);
            $status = 'saved';
        }

        return response()->json([
            'success' => true,
            'status' => $status,
            'saves_count' => StoreSave::where('store_id', $store->id)->count(),
        ]);
    }

    public function mySave(Request $request, Store $store): JsonResponse
    {
        return response()->json([
            'success' => true,
            'is_saved' => StoreSave::where('store_id', $store->id)->where('user_id', $request->user()->id)->exists(),
            'saves_count' => StoreSave::where('store_id', $store->id)->count(),
        ]);
    }

    /** Same shop shape as StoreReviewController::myReviews (so the same card renders it), newest save first. */
    public function mySavedStores(Request $request): JsonResponse
    {
        $saves = StoreSave::where('user_id', $request->user()->id)
            ->with([
                'store:id,name,slug,logo_path,banner_path,city,operating_hours',
                'store.branches:id,store_id,name,city',
            ])
            ->latest()
            ->get()
            ->filter(fn (StoreSave $s) => $s->store !== null)
            ->map(function (StoreSave $s) {
                $store = $s->store;
                $store->loadCount('reviews');
                $store->loadAvg('reviews', 'rating');

                return [
                    'saved_at' => $s->created_at,
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

        return response()->json(['success' => true, 'data' => $saves]);
    }
}
