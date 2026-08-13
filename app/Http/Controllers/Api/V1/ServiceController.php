<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreServiceRequest;
use App\Models\Shop;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request, Shop $shop): JsonResponse
    {
        $query = $shop->services()->with('pricing');

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    /**
     * Publicly accessible list of a shop's active services for its storefront page.
     */
    public function publicIndex(Shop $shop): JsonResponse
    {
        $services = $shop->services()
            ->where('is_active', true)
            ->get(['id', 'name', 'description', 'categories', 'base_price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'estimated_days', 'is_active', 'image_url', 'custom_fields', 'size_chart_image_url', 'size_chart_columns', 'size_chart_rows']);

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    /**
     * A dedicated, minimal endpoint for the "Set Sale Price" quick action —
     * update()'s StoreServiceRequest requires the full pricing_tiers array on
     * every save, which a lightweight sale-only action shouldn't have to
     * reconstruct just to toggle a discount.
     */
    public function updateSale(Request $request, Shop $shop, Service $service): JsonResponse
    {
        if ($service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            // A sale price that isn't actually below base_price isn't a
            // sale — the frontend's own Set Sale Price modal already blocks
            // this client-side, but nothing stopped it being set directly
            // via the API, storing a "discount" that discounts nothing.
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lt:' . (float) $service->base_price],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after_or_equal:sale_starts_at'],
        ], [
            'sale_price.lt' => 'The sale price must be lower than the base price (₱' . number_format((float) $service->base_price, 2) . ').',
        ]);

        $service->update([
            'sale_price' => $validated['sale_price'] ?? null,
            'sale_starts_at' => $validated['sale_starts_at'] ?? null,
            'sale_ends_at' => $validated['sale_ends_at'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => $service->fresh('pricing')]);
    }

    public function store(StoreServiceRequest $request, Shop $shop): JsonResponse
    {
        $validated = $request->validated();
        $tiers = $validated['pricing_tiers'];
        unset($validated['pricing_tiers']);

        // tags stays a denormalized mirror of the tier labels so existing
        // displays (service cards, job-order service picker) keep working
        // without having to join against pricing on every read.
        $validated['tags'] = array_column($tiers, 'label');

        $service = $shop->services()->create($validated);
        $this->syncPricingTiers($service, $tiers);

        return response()->json(['success' => true, 'data' => $service->load('pricing')], 201);
    }

    public function update(StoreServiceRequest $request, Shop $shop, Service $service): JsonResponse
    {
        if ($service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validated();
        $tiers = $validated['pricing_tiers'];
        unset($validated['pricing_tiers']);
        $validated['tags'] = array_column($tiers, 'label');

        $service->update($validated);

        // The main edit form doesn't touch sale_price at all (that's
        // updateSale()'s job, which already validates sale_price < base_price
        // at write time) — but editing base_price here can silently leave a
        // previously-valid sale_price stale and inverted (e.g. base_price
        // drops from ₱1000 to ₱700 while a ₱800 sale_price from before is
        // still on the row). The storefront's own getActiveSale() already
        // ignores a sale_price >= price, so customers never see a fake
        // "markup disguised as a discount" — but the owner's dashboard would
        // still show that stale number as if a sale were configured. Clear
        // it here so the data itself stays consistent, not just its display.
        if ($service->sale_price !== null && (float) $service->sale_price >= (float) $service->base_price) {
            $service->update(['sale_price' => null, 'sale_starts_at' => null, 'sale_ends_at' => null]);
        }

        $this->syncPricingTiers($service, $tiers);

        return response()->json(['success' => true, 'data' => $service->fresh('pricing')]);
    }

    /**
     * Included Services & Pricing is edited as one list in the form, so the
     * simplest correct sync is a full replace rather than diffing individual rows.
     */
    private function syncPricingTiers(Service $service, array $tiers): void
    {
        $service->pricing()->delete();
        foreach ($tiers as $tier) {
            $service->pricing()->create([
                'label' => $tier['label'],
                'amount' => $tier['amount'] ?? 0,
            ]);
        }
    }

    public function destroy(Request $request, Shop $shop, Service $service): JsonResponse
    {
        if ($service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Same accountability gap job_order_deleted/staff_removed already
        // closed — deleting a service definition previously left no trace
        // in the Audit Log at all.
        $shop->auditLogs()->create([
            'user_id'    => $request->user()->id,
            'action'     => 'service_deleted',
            'model_type' => Service::class,
            'model_id'   => $service->id,
            'payload'    => ['name' => $service->name],
            'ip_address' => $request->ip(),
        ]);

        $service->delete();

        return response()->json(['success' => true]);
    }

    public function restore(Request $request, Shop $shop, int $serviceId): JsonResponse
    {
        $service = Service::onlyTrashed()->where('id', $serviceId)->first();

        if (!$service || $service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Deleted service not found.'], 404);
        }

        $service->restore();

        $shop->auditLogs()->create([
            'user_id'    => $request->user()->id,
            'action'     => 'service_restored',
            'model_type' => Service::class,
            'model_id'   => $service->id,
            'payload'    => ['name' => $service->name],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['success' => true, 'data' => $service->load('pricing')]);
    }

}
