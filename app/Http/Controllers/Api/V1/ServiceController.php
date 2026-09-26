<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreServiceRequest;
use App\Models\Service;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        $query = $store->services()->with('pricing');

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    /**
     * Cross-store services showroom feed for the public landing page —
     * publicIndex() below is always scoped to one store; this pulls active
     * services (Alterations, Bespoke Tailoring, Sublimation, etc.) across
     * every approved, non-hidden store, the same way CatalogController::
     * publicShowroom() does for catalog items. Services are a distinct
     * concept from catalog items here — a service like "Alterations" isn't
     * a garment_type value, it belongs in this feed, not the catalog grid.
     */
    public function publicShowroom(Request $request): JsonResponse
    {
        $query = Service::query()
            ->where('is_active', true)
            ->whereHas('store', fn ($q) => $q->where('is_hidden', false)->where('status', 'approved'))
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->with([
                'store' => fn ($q) => $q->with([
                    'owner:id,name',
                    'branches' => fn ($bq) => $bq->where('status', 'active'),
                ]),
            ]);

        if ($request->filled('q')) {
            $search = strtolower((string) $request->string('q'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(category) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(service_type) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$search.'%']);
            });
        }

        if ($request->filled('district')) {
            $district = $request->string('district')->toString();
            $query->whereHas('store.branches', function ($bq) use ($district) {
                $bq->where('district', $district)->where('status', 'active');
            });
        }

        match ($request->string('sort_by')->toString()) {
            'name_asc' => $query->orderBy('name'),
            'price_asc' => $query->orderBy('base_price'),
            'price_desc' => $query->orderByDesc('base_price'),
            default => $query->latest(),
        };

        $services = $query->paginate($request->input('per_page', 12));

        // base_price/sale_price are declared 'decimal:2' on the model, which
        // Laravel always re-stringifies on assignment (by design, to protect
        // money precision) -- setting $service->base_price = (float) ... gets
        // silently cast right back to a string, unlike CatalogItem::price
        // (no cast at all, so a plain float assignment sticks there).
        // Converting to a plain array first sidesteps the model's own cast
        // for just this response.
        $items = $services->getCollection()->map(function (Service $service) {
            $arr = $service->toArray();
            $arr['base_price'] = $service->base_price !== null ? (float) $service->base_price : null;
            $arr['sale_price'] = $service->sale_price !== null ? (float) $service->sale_price : null;
            // withAvg() returns reviews_avg_rating as whatever PDO hands back
            // for AVG() (a numeric string, not a float) — same rounding as
            // StoreController::publicIndex/CatalogController do for the same reason.
            $arr['reviews_avg_rating'] = $service->reviews_avg_rating !== null ? round((float) $service->reviews_avg_rating, 1) : null;

            return $arr;
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $services->currentPage(),
                'last_page' => $services->lastPage(),
                'per_page' => $services->perPage(),
                'total' => $services->total(),
            ],
        ]);
    }

    /**
     * Publicly accessible list of a store's active services for its storefront page.
     */
    public function publicIndex(Store $store): JsonResponse
    {
        // service_types + pricing added for the customer-facing "Request a
        // Repair" flow — it needs to know which services are actually
        // alteration/repair-typed, and their real per-item pricing (never a
        // guessed flat amount) to build the request form.
        $services = $store->services()
            ->where('is_active', true)
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->with([
                'pricing:id,service_id,label,amount',
                // Individual reviews (star-only, no comment column on this
                // table — see ServiceReview's own docblock) so the service
                // detail page's "View All" ratings screen has something to
                // list, the same way CatalogController::publicShowroom's
                // sibling item-detail lookup already embeds catalog reviews.
                'reviews' => fn ($q) => $q->with('user:id,name,profile_picture')->latest(),
            ])
            ->get(['id', 'name', 'description', 'categories', 'service_types', 'base_price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'estimated_days', 'is_active', 'image_url', 'custom_fields', 'size_chart_image_url', 'size_chart_columns', 'size_chart_rows'])
            ->each(function (Service $service) {
                // withAvg() returns a numeric string, not a float — round it
                // the same way ServiceController::publicShowroom does.
                $service->reviews_avg_rating = $service->reviews_avg_rating !== null
                    ? round((float) $service->reviews_avg_rating, 1)
                    : null;
            });

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
    public function updateSale(Request $request, Store $store, Service $service): JsonResponse
    {
        if ($service->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            // A sale price that isn't actually below base_price isn't a
            // sale — the frontend's own Set Sale Price modal already blocks
            // this client-side, but nothing stopped it being set directly
            // via the API, storing a "discount" that discounts nothing.
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lt:'.(float) $service->base_price],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after_or_equal:sale_starts_at'],
        ], [
            'sale_price.lt' => 'The sale price must be lower than the base price (₱'.number_format((float) $service->base_price, 2).').',
        ]);

        $service->update([
            'sale_price' => $validated['sale_price'] ?? null,
            'sale_starts_at' => $validated['sale_starts_at'] ?? null,
            'sale_ends_at' => $validated['sale_ends_at'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => $service->fresh('pricing')]);
    }

    public function store(StoreServiceRequest $request, Store $store): JsonResponse
    {
        $validated = $request->validated();
        $tiers = $validated['pricing_tiers'];
        unset($validated['pricing_tiers']);

        // tags stays a denormalized mirror of the tier labels so existing
        // displays (service cards, job-order service picker) keep working
        // without having to join against pricing on every read.
        $validated['tags'] = array_column($tiers, 'label');

        $service = $store->services()->create($validated);
        $this->syncPricingTiers($service, $tiers);

        return response()->json(['success' => true, 'data' => $service->load('pricing')], 201);
    }

    public function update(StoreServiceRequest $request, Store $store, Service $service): JsonResponse
    {
        if ($service->store_id !== $store->id) {
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

    public function destroy(Request $request, Store $store, Service $service): JsonResponse
    {
        if ($service->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Same accountability gap job_order_deleted/staff_removed already
        // closed — deleting a service definition previously left no trace
        // in the Audit Log at all.
        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'service_deleted',
            'model_type' => Service::class,
            'model_id' => $service->id,
            'payload' => ['name' => $service->name],
            'ip_address' => $request->ip(),
        ]);

        $service->delete();

        return response()->json(['success' => true]);
    }

    public function restore(Request $request, Store $store, int $serviceId): JsonResponse
    {
        $service = Service::onlyTrashed()->where('id', $serviceId)->first();

        if (! $service || $service->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Deleted service not found.'], 404);
        }

        $service->restore();

        $store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'service_restored',
            'model_type' => Service::class,
            'model_id' => $service->id,
            'payload' => ['name' => $service->name],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['success' => true, 'data' => $service->load('pricing')]);
    }
}
