<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\ServicePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServicePackageController extends Controller
{
    public function index(Shop $shop): JsonResponse
    {
        $packages = $shop->servicePackages()->with('services')->get();

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    /**
     * Publicly accessible list of a shop's active packages for its storefront page.
     */
    public function publicIndex(Shop $shop): JsonResponse
    {
        $packages = $shop->servicePackages()
            ->where('is_active', true)
            ->with('services:id,name,base_price')
            ->get(['id', 'shop_id', 'name', 'description', 'bundle_price']);

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    public function store(Request $request, Shop $shop): JsonResponse
    {
        $validated = $this->validatePackage($request, $shop);

        $package = $shop->servicePackages()->create([
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

    public function update(Request $request, Shop $shop, ServicePackage $servicePackage): JsonResponse
    {
        if ($servicePackage->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $this->validatePackage($request, $shop);

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

    public function destroy(Shop $shop, ServicePackage $servicePackage): JsonResponse
    {
        if ($servicePackage->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $servicePackage->delete();

        return response()->json(['success' => true]);
    }

    /**
     * A package only makes sense as a bundle of 2+ real services belonging to
     * this shop — a single-service "package" is just that service.
     */
    private function validatePackage(Request $request, Shop $shop): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'bundle_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'service_ids' => ['required', 'array', 'min:2'],
            'service_ids.*' => [Rule::exists('services', 'id')->where('shop_id', $shop->id)],
        ]);
    }
}
