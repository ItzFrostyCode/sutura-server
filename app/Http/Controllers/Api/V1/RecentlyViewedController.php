<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\RecentlyViewed;
use App\Models\Service;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecentlyViewedController extends Controller
{
    /**
     * Fire-and-forget: called from a shop page, a catalog item page, or a
     * service card's click handler (services have no dedicated detail page
     * to mount an effect on, so that one's recorded on click instead).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:' . implode(',', RecentlyViewed::TYPES),
            'id'   => 'required|integer',
        ]);

        RecentlyViewed::record($request->user()->id, $validated['type'], $validated['id']);

        return response()->json(['success' => true]);
    }

    /**
     * Cross-shop "Recently Viewed" — same customer-scoped, no-role-gate
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
                'service'      => Service::class,
                'shop'         => Shop::class,
                default        => null,
            };
            if ($modelClass) $query->where('viewable_type', $modelClass);
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
            Service::class     => 'service',
            Shop::class        => 'shop',
            default            => 'unknown',
        };

        $shape = match ($type) {
            'catalog_item' => $this->catalogItemShape($entity),
            'service'      => $this->serviceShape($entity),
            'shop'         => $this->shopShape($entity),
            default        => [],
        };

        return array_merge(['type' => $type, 'viewed_at' => $row->viewed_at], $shape);
    }

    private function catalogItemShape(CatalogItem $item): array
    {
        $item->loadMissing(['images', 'shop:id,name,slug']);
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
            'shop' => $item->shop ? ['name' => $item->shop->name, 'slug' => $item->shop->slug] : null,
        ];
    }

    private function serviceShape(Service $service): array
    {
        $service->loadMissing('shop:id,name,slug');

        return [
            'id' => $service->id,
            'name' => $service->name,
            'base_price' => $service->base_price,
            'estimated_days' => $service->estimated_days,
            'shop' => $service->shop ? ['name' => $service->shop->name, 'slug' => $service->shop->slug] : null,
        ];
    }

    private function shopShape(Shop $shop): array
    {
        return [
            'id' => $shop->id,
            'name' => $shop->name,
            'slug' => $shop->slug,
            'logo_path' => $shop->logo_path,
            'city' => $shop->city,
        ];
    }
}
