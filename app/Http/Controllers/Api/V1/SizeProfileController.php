<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SizeProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer's own standing body-measurement profile — cross-shop, unlike
 * MeasurementController's shop-scoped fitting-history records. Filled in
 * once by the customer and reused as the "Size Guide" reference on every
 * catalog item's product page.
 */
class SizeProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = SizeProfile::where('customer_id', $request->user()->id)->first();

        return response()->json([
            'success' => true,
            'data' => $profile,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'height_cm' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'metrics' => ['nullable', 'array'],
            'metrics.*' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
        ]);

        $profile = SizeProfile::updateOrCreate(
            ['customer_id' => $request->user()->id],
            $validated
        );

        return response()->json([
            'success' => true,
            'data' => $profile,
        ]);
    }
}
