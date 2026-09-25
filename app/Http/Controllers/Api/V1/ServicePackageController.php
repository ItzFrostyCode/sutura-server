<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServicePackageController extends Controller
{
    public function index(Store $store): JsonResponse
    {
        $packages = $store->servicePackages()->with('services')->get();

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    /**
     * Publicly accessible list of a store's active packages for its storefront page.
     */
    public function publicIndex(Store $store): JsonResponse
    {
        $packages = $store->servicePackages()
            ->where('is_active', true)
            ->with('services:id,name,base_price')
            ->get(['id', 'store_id', 'name', 'description', 'bundle_price']);

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        $validated = $this->validatePackage($request, $store);

        $package = $store->servicePackages()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'bundle_price' => $validated['bundle_price'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $package->services()->sync($validated['service_ids']);

        return response()->json([
            'success' => true,
            'data' => $package->load('services'),
        ], 201);
    }

    public function update(Request $request, Store $store, ServicePackage $servicePackage): JsonResponse
    {
        if ($servicePackage->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $this->validatePackage($request, $store);

        $servicePackage->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'bundle_price' => $validated['bundle_price'] ?? null,
            'is_active' => $validated['is_active'] ?? $servicePackage->is_active,
        ]);

        $servicePackage->services()->sync($validated['service_ids']);

        return response()->json([
            'success' => true,
            'data' => $servicePackage->fresh('services'),
        ]);
    }

    public function destroy(Store $store, ServicePackage $servicePackage): JsonResponse
    {
        if ($servicePackage->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $servicePackage->delete();

        return response()->json(['success' => true]);
    }

    /**
     * A package only makes sense as a bundle of 2+ real services belonging to
     * this store — a single-service "package" is just that service.
     */
    private function validatePackage(Request $request, Store $store): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'bundle_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            // 'distinct' belongs on the wildcard item, not the parent array
            // — Laravel's own rule only actually inspects duplicates that
            // way. Without it, [2, 2] passed the 'min:2' count check while
            // only ever bundling one real service (sync() silently dedupes
            // the pivot), defeating the "2+ services" requirement entirely.
            'service_ids' => ['required', 'array', 'min:2'],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('services', 'id')->where('store_id', $store->id)],
        ]);
    }
}
