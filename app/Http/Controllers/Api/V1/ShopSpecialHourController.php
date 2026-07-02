<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ShopSpecialHour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopSpecialHourController extends Controller
{
    public function index(Shop $shop): JsonResponse
    {
        $specialHours = $shop->specialHours()
            ->where('end_date', '>=', now()->toDateString())
            ->orderBy('start_date', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $specialHours
        ]);
    }

    public function store(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate([
            'title'                => ['required', 'string', 'max:255'],
            'start_date'           => ['required', 'date'],
            'end_date'             => ['required', 'date', 'after_or_equal:start_date'],
            'is_closed'            => ['required', 'boolean'],
            'special_open_time'    => ['nullable', 'string', 'required_if:is_closed,false'],
            'special_close_time'   => ['nullable', 'string', 'required_if:is_closed,false'],
            'announcement_message' => ['nullable', 'string', 'max:2000'],
        ]);

        $specialHour = $shop->specialHours()->create($validated);

        return response()->json([
            'success' => true,
            'data'    => $specialHour
        ], 201);
    }

    public function update(Request $request, Shop $shop, ShopSpecialHour $specialHour): JsonResponse
    {
        if ($specialHour->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'title'                => ['required', 'string', 'max:255'],
            'start_date'           => ['required', 'date'],
            'end_date'             => ['required', 'date', 'after_or_equal:start_date'],
            'is_closed'            => ['required', 'boolean'],
            'special_open_time'    => ['nullable', 'string', 'required_if:is_closed,false'],
            'special_close_time'   => ['nullable', 'string', 'required_if:is_closed,false'],
            'announcement_message' => ['nullable', 'string', 'max:2000'],
        ]);

        $specialHour->update($validated);

        return response()->json([
            'success' => true,
            'data'    => $specialHour
        ]);
    }

    public function destroy(Shop $shop, ShopSpecialHour $specialHour): JsonResponse
    {
        if ($specialHour->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $specialHour->delete();

        return response()->json([
            'success' => true,
            'message' => 'Special schedule removed.'
        ]);
    }
}
