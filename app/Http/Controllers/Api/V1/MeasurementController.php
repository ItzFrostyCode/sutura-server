<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreMeasurementRequest;
use App\Models\Shop;
use App\Models\Measurement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeasurementController extends Controller
{
    public function index(Shop $shop, Request $request): JsonResponse
    {
        // Returns every version of every profile — the frontend groups these
        // by profile_name and lets the shop owner switch between versions
        // client-side (see MeasurementList's version selector), so history
        // has to be in this same response, not a separate endpoint.
        $query = $shop->measurements()->with('customer:id,name,email');

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    public function store(StoreMeasurementRequest $request, Shop $shop): JsonResponse
    {
        $measurement = $shop->measurements()->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $measurement->load('customer:id,name')
        ], 201);
    }

    public function show(Shop $shop, Measurement $measurement): JsonResponse
    {
        if ($measurement->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $measurement->load('customer:id,name')
        ]);
    }

    public function update(Request $request, Shop $shop, Measurement $measurement): JsonResponse
    {
        if ($measurement->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // A superseded (past) version is a read-only historical record — you
        // view it, you don't edit it. Only the current version can be saved,
        // which is what actually creates the next version.
        if ($measurement->superseded_at !== null) {
            return response()->json(['success' => false, 'message' => 'This is a past version and cannot be edited. View the current version to make changes.'], 422);
        }

        $validated = $request->validate([
            'source' => 'nullable|in:shop_owner,customer',
            'metrics' => 'sometimes|array',
            'measurements' => 'sometimes|array',
            'notes' => 'nullable|string'
        ]);

        if (isset($validated['measurements'])) {
            $validated['metrics'] = $validated['measurements'];
            unset($validated['measurements']);
        }

        // Saving an edit never overwrites the current row in place — it closes
        // out this version (superseded_at) and inserts the next one, so a
        // shop owner can always look back at what a customer's measurements
        // were at an earlier fitting instead of losing that the moment it's
        // updated.
        $measurement->update(['superseded_at' => now()]);

        $nextVersion = $shop->measurements()->create([
            'customer_id' => $measurement->customer_id,
            'source' => $validated['source'] ?? $measurement->source,
            'profile_name' => $measurement->profile_name,
            'version' => $measurement->version + 1,
            'metrics' => $validated['metrics'] ?? $measurement->metrics,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $measurement->notes,
        ]);

        return response()->json([
            'success' => true,
            'data' => $nextVersion->load('customer:id,name')
        ]);
    }

    public function destroy(Shop $shop, Measurement $measurement): JsonResponse
    {
        if ($measurement->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Deleting a profile removes its whole version history, not just the
        // current snapshot — otherwise old versions would be left orphaned
        // with no current row pointing at them.
        Measurement::where('shop_id', $shop->id)
            ->where('customer_id', $measurement->customer_id)
            ->where('profile_name', $measurement->profile_name)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Measurement deleted successfully'
        ]);
    }
}
