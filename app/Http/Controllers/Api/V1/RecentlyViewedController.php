<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\RecentlyViewed;
use App\Models\Service;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecentlyViewedController extends Controller
{
    /**
     * Fire-and-forget: called from a store page, a catalog item page, or a
     * service card's click handler (services have no dedicated detail page
     * to mount an effect on, so that one's recorded on click instead).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:'.implode(',', RecentlyViewed::TYPES),
            'id' => 'required|integer',
        ]);

        RecentlyViewed::record($request->user()->id, $validated['type'], $validated['id']);

        return response()->json(['success' => true]);
    }

    /**
     * Cross-store "Recently Viewed" — same customer-scoped, no-role-gate
     * pattern as /my-orders, /my-appointments, /my-measurements. Optional
     * ?type= filters to one tab; omitted returns all three.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RecentlyViewed::where('user_id', $request->user()->id)
            ->with('viewable');

        if ($request->filled('type')) {
            $type = $request->string('type')->toString();
            $modelClass = match ($type) {
                'catalog_item' => CatalogItem::class,
                'service' => Service::class,
                'store' => Store::class,
                default => null,
            };
            if ($modelClass) {
                $query->where('viewable_type', $modelClass);
            }
        }

        $rows = $query->orderByDesc('viewed_at')->limit(60)->get();

        $data = $rows
            ->filter(fn (RecentlyViewed $row) => $row->viewable !== null)
            ->map(fn (RecentlyViewed $row) => $this->transform($row))
            ->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function transform(RecentlyViewed $row): array
    {
        $entity = $row->viewable;
        $type = match (get_class($entity)) {
            CatalogItem::class => 'catalog_item',
            Service::class => 'service',
            Store::class => 'store',
            default => 'unknown',
        };

        $shape = match ($type) {
            'catalog_item' => $this->catalogItemShape($entity),
            'service' => $this->serviceShape($entity),
            'store' => $this->storeShape($entity),
            default => [],
        };

        return array_merge(['type' => $type, 'viewed_at' => $row->viewed_at], $shape);
    }

    private function catalogItemShape(CatalogItem $item): array
    {
        $item->loadMissing(['images', 'store:id,name,slug']);
        $item->loadCount(['reviews', 'catalogOrders', 'jobOrders']);
        $item->loadAvg('reviews', 'rating');

        return [
            'id' => $item->id,
            'name' => $item->name,
            'price' => $item->price,
            'material' => $item->material,
            'estimated_days' => $item->estimated_days,
            'reviews_count' => $item->reviews_count,
            'reviews_avg_rating' => $item->reviews_avg_rating !== null ? round((float) $item->reviews_avg_rating, 1) : null,
            'order_count' => $item->catalog_orders_count + $item->job_orders_count,
            'images' => $item->images->map(fn ($img) => ['image_url' => $img->image_url, 'is_primary' => $img->is_primary])->values(),
            'store' => $item->store ? ['name' => $item->store->name, 'slug' => $item->store->slug] : null,
        ];
    }

    private function serviceShape(Service $service): array
    {
        $service->loadMissing('store:id,name,slug');
        $service->loadCount('reviews');
        $service->loadAvg('reviews', 'rating');

        return [
            'id' => $service->id,
            'name' => $service->name,
            'category' => $service->category,
            'image_url' => $service->image_url,
            'base_price' => $service->base_price,
            'estimated_days' => $service->estimated_days,
            'reviews_count' => $service->reviews_count,
            'reviews_avg_rating' => $service->reviews_avg_rating ? round($service->reviews_avg_rating, 1) : null,
            'store' => $service->store ? ['name' => $service->store->name, 'slug' => $service->store->slug] : null,
        ];
    }

    private function storeShape(Store $store): array
    {
        $store->loadMissing('branches:id,store_id,name,city');
        $store->loadCount('reviews');
        $store->loadAvg('reviews', 'rating');

        return [
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
    }
}
