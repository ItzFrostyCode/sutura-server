<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreServiceRequest;
use App\Models\CustomApparelType;
use App\Models\Service;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Support\CanonicalTaxonomy;
use App\Support\SearchIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        $query = $store->services()->with([
            'pricing',
            'catalogItems' => fn ($q) => $q->with('images')
                ->withCount(['saves', 'reviews', 'catalogOrders', 'jobOrders'])
                ->withAvg('reviews', 'rating'),
        ])
            ->withCount(['reviews', 'saves', 'jobOrders', 'catalogItems'])
            ->withAvg('reviews', 'rating')
            ->withSum('jobOrders as job_revenue', 'total_amount')
            ->withSum('jobOrders as job_balance_sum', 'balance')
            ->withSum('jobOrders as job_discount_sum', 'discount_amount');

        $services = $query->get();
        $services->each(function ($service) {
            $service->reviews_avg_rating = $service->reviews_avg_rating !== null ? round((float) $service->reviews_avg_rating, 1) : null;
            $service->total_revenue = (float) $service->job_revenue - (float) $service->job_balance_sum - (float) $service->job_discount_sum;
            $service->orders_count = (int) ($service->job_orders_count ?? 0);
            $service->saves_count = (int) ($service->saves_count ?? 0);
            $service->catalogItems->each(function ($item) {
                $item->reviews_avg_rating = $item->reviews_avg_rating !== null ? round((float) $item->reviews_avg_rating, 1) : null;
                $item->order_count = (int) (($item->catalog_orders_count ?? 0) + ($item->job_orders_count ?? 0));
                $item->saves_count = (int) ($item->saves_count ?? 0);
            });
        });

        return response()->json([
            'success' => true,
            'data' => $services,
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
                'pricing:id,service_id,label,amount',
                'store' => fn ($q) => $q->with([
                    'owner:id,name',
                    'branches' => fn ($bq) => $bq->live(),
                ]),
            ]);

        // Same reading of the search as the shop list (App\Support\SearchIntent); plain text when it names no
        // work and no garment.
        $intent = $request->filled('q') ? SearchIntent::parse((string) $request->string('q')) : null;
        if ($intent !== null) {
            SearchIntent::constrainServices($query, $intent);
        } elseif ($request->filled('q')) {
            $search = strtolower((string) $request->string('q'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(category) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(service_type) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(description) LIKE ?', ['%'.$search.'%']);
            });
        }

        if ($place = StoreBranch::placeFilter($request)) {
            $query->whereHas('store.branches', $place);
        }

        // One of the 4 services (CanonicalTaxonomy::SERVICE_GROUPS) — /search?tab=services and the
        // All Categories page filter on it, distinct from the free-text category search above.
        if ($request->filled('service_category')) {
            $query->whereRaw('LOWER(service_category) = ?', [strtolower($request->string('service_category'))]);
        }
        // Services that work on a garment category: ticked one of its garments, or have a listed design of one.
        if ($request->filled('garment_category')) {
            $garments = CanonicalTaxonomy::garmentsInCategory((string) $request->string('garment_category'));
            $query->where(function ($g) use ($garments) {
                $g->whereRaw('1 = 0');
                foreach ($garments as $slug) {
                    $g->orWhereJsonContains('garment_types', $slug);
                }
                $g->orWhereHas('catalogItems', fn ($ci) => $ci->listed()->ofGarments($garments === [] ? ['-'] : $garments));
            });
        }
        // A task the service ticked (2-level taxonomy capability, e.g. zipper_replacement) — the capability
        // chips on All Categories link here.
        if ($request->filled('capability')) {
            $query->whereJsonContains('capabilities', (string) $request->string('capability'));
        }

        if ($request->filled('min_price')) {
            $query->where('base_price', '>=', $request->float('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('base_price', '<=', $request->float('max_price'));
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
            $minTier = $service->relationLoaded('pricing')
                ? $service->pricing->where('amount', '>', 0)->min('amount')
                : null;
            $arr['base_price'] = $service->base_price !== null && (float) $service->base_price > 0
                ? (float) $service->base_price
                : ($minTier !== null ? (float) $minTier : null);
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
            ->withCount(['reviews', 'saves', 'jobOrders'])
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
            ->get([
                'id', 'store_id', 'name', 'description', 'categories', 'service_types', 'service_category',
                'garment_types', 'capabilities', 'base_price', 'sale_price', 'sale_starts_at', 'sale_ends_at',
                'estimated_days', 'estimated_days_max', 'is_active', 'image_url', 'custom_fields', 'roster_fields',
                'min_order_qty', 'size_chart_image_url', 'size_chart_columns', 'size_chart_rows',
                'measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent',
            ])
            ->each(function (Service $service) {
                // withAvg() returns a numeric string, not a float — round it
                // the same way ServiceController::publicShowroom does.
                $service->reviews_avg_rating = $service->reviews_avg_rating !== null
                    ? round((float) $service->reviews_avg_rating, 1)
                    : null;
                $service->orders_count = (int) ($service->job_orders_count ?? 0);
                $service->saves_count = (int) ($service->saves_count ?? 0);
            });

        return response()->json([
            'success' => true,
            'data' => $services,
        ]);
    }

    /**
     * Store service detail for dashboard owner/staff views.
     */
    public function show(Request $request, Store $store, Service $service): JsonResponse
    {
        if ($service->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $service->load([
            'pricing',
            'catalogItems' => fn ($q) => $q->with('images')
                ->withCount(['saves', 'reviews', 'catalogOrders', 'jobOrders'])
                ->withAvg('reviews', 'rating'),
            'reviews' => fn ($q) => $q->with('user:id,name,profile_picture')->latest(),
        ]);
        $service->loadCount(['reviews', 'saves', 'jobOrders', 'catalogItems']);
        $service->loadAvg('reviews', 'rating');
        $service->reviews_avg_rating = $service->reviews_avg_rating !== null
            ? round((float) $service->reviews_avg_rating, 1)
            : null;
        $service->orders_count = (int) ($service->job_orders_count ?? 0);
        $service->saves_count = (int) ($service->saves_count ?? 0);
        $service->catalogItems->each(function ($item) {
            $item->reviews_avg_rating = $item->reviews_avg_rating !== null ? round((float) $item->reviews_avg_rating, 1) : null;
            $item->order_count = (int) (($item->catalog_orders_count ?? 0) + ($item->job_orders_count ?? 0));
            $item->saves_count = (int) ($item->saves_count ?? 0);
        });

        return response()->json([
            'success' => true,
            'data' => $service,
        ]);
    }

    public function store(StoreServiceRequest $request, Store $store): JsonResponse
    {
        $validated = $request->validated();
        $tiers = $validated['pricing_tiers'] ?? [];
        unset($validated['pricing_tiers']);

        // tags stays a denormalized mirror of the tier labels so existing
        // displays (service cards, job-order service picker) keep working
        // without having to join against pricing on every read.
        $validated['tags'] = array_column($tiers, 'label');
        $validated = $this->withTaxonomy($validated, null, $store);

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
        $hasTiers = array_key_exists('pricing_tiers', $validated) && $validated['pricing_tiers'] !== null;
        if ($hasTiers) {
            $tiers = $validated['pricing_tiers'];
            unset($validated['pricing_tiers']);
            $validated['tags'] = array_column($tiers, 'label');
        } else {
            unset($validated['pricing_tiers']);
        }
        $validated = $this->withTaxonomy($validated, $service, $store);

        $service->update($validated);

        // The main edit form doesn't touch sale_price at all (that's
        // the old sale endpoint's job (now removed)
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

        if ($hasTiers) {
            $this->syncPricingTiers($service, $tiers);
        }

        return response()->json(['success' => true, 'data' => $service->fresh(['pricing', 'reviews'])]);
    }

    /**
     * Garments saved as their current slug (an old 4-level slug becomes the garment it was merged into), and
     * the order types worked out from the service group, capabilities and garments (Service::deriveOrderTypes)
     * — a service with no approved group keeps what it was sent / already had.
     */
    private function withTaxonomy(array $validated, ?Service $existing, Store $store): array
    {
        if (array_key_exists('garment_types', $validated)) {
            // A garment from the list is saved as its current slug; a typed name stays as typed and is filed for
            // SUTURA to check (CustomApparelType), the same as a design's. A name already refused cannot be reused.
            $garments = collect($validated['garment_types'] ?? [])
                ->map(fn ($g) => CanonicalTaxonomy::canonicalGarment($g) ?? trim((string) $g))
                ->filter()->unique(fn ($g) => strtolower($g))->values()->all();
            foreach ($garments as $g) {
                if (CanonicalTaxonomy::canonicalGarment($g) === null) {
                    $type = CustomApparelType::resolveFor($g, $store);
                    if ($type->status === 'rejected') {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'garment_types' => ['The garment name "'.$type->label.'" was not approved by SUTURA'.($type->rejection_reason ? ': '.$type->rejection_reason : '.')],
                        ]);
                    }
                }
            }
            $validated['garment_types'] = $garments === [] ? null : $garments;
        }
        if (array_key_exists('capabilities', $validated)) {
            $caps = array_values(array_unique($validated['capabilities'] ?? []));
            $validated['capabilities'] = $caps === [] ? null : $caps;
        }

        $group = array_key_exists('service_category', $validated) ? $validated['service_category'] : $existing?->service_category;
        $derived = Service::deriveOrderTypes(
            $group,
            $validated['capabilities'] ?? ($existing?->capabilities ?? []),
            $validated['garment_types'] ?? ($existing?->garment_types ?? []),
        );
        if ($derived !== null) {
            $validated['service_types'] = $derived;
        }

        return $validated;
    }

    /**
     * Included Services & Pricing is edited as one list in the form, so the
     * simplest correct sync is a full replace rather than diffing individual rows.
     */
    private function syncPricingTiers(Service $service, array $tiers): void
    {
        $service->pricing()->delete();
        foreach ($tiers as $tier) {
            $label = trim((string) ($tier['label'] ?? ''));
            if ($label !== '') {
                $service->pricing()->create([
                    'label' => $label,
                    'amount' => $tier['amount'] ?? 0,
                ]);
            }
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
}
