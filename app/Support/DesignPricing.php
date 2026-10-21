<?php

namespace App\Support;

use App\Models\CatalogItem;
use App\Models\Service;

/**
 * What a design costs. A design is a style under a service, not a product with its own base price:
 * by default it costs what its service costs. The shop can add an extra charge for the design, or
 * say it needs a quotation. Service prices are starting prices; the confirmed price is the order's
 * quotation (App\Support\OrderQuotation), which the customer approves before production.
 *
 * A design with no service keeps its own stored price (there is nothing to inherit from).
 */
final class DesignPricing
{
    public const SAME = 'same';

    public const SET = 'set';

    public const EXTRA = 'extra';

    public const QUOTE = 'quote';

    public const MODES = [self::SAME, self::SET, self::EXTRA, self::QUOTE];

    /** Columns a service needs for resolve(). */
    public const SERVICE_COLUMNS = ['id', 'base_price', 'sale_price', 'sale_starts_at', 'sale_ends_at'];

    /**
     * `amount` is the price the customer starts from (null when the shop has to quote it);
     * `regular_amount` is that amount without the service's sale, set only while a sale is on.
     *
     * @return array{mode:string, service_price:?float, extra_charge:?float, amount:?float, regular_amount:?float, needs_quote:bool}
     */
    public static function resolve(CatalogItem $item, ?Service $service): array
    {
        if (! $service) {
            $own = (float) $item->price > 0 ? round((float) $item->price, 2) : null;

            return self::result('own', null, null, $own, null);
        }

        $mode = in_array($item->pricing_mode, self::MODES, true) ? $item->pricing_mode : self::SAME;
        $regular = $service->base_price !== null && (float) $service->base_price > 0 ? (float) $service->base_price : null;
        $current = $regular !== null ? $service->effectivePrice() : null;
        $extra = $mode === self::EXTRA ? round(max(0, (float) $item->extra_charge), 2) : null;

        if ($mode === self::SET) {
            $own = (float) $item->price > 0 ? round((float) $item->price, 2) : null;

            return self::result(self::SET, $current, null, $own, null);
        }

        if ($mode === self::QUOTE || $current === null) {
            return self::result($mode, $current, $extra, null, null);
        }

        $amount = round($current + ($extra ?? 0), 2);
        $regularAmount = $current < $regular ? round($regular + ($extra ?? 0), 2) : null;

        return self::result($mode, $current, $extra, $amount, $regularAmount);
    }

    /**
     * Put `pricing` on each design, loading the services' prices in one query.
     *
     * @param  iterable<CatalogItem|null>  $items
     */
    public static function attach(iterable $items): void
    {
        $items = collect($items)->filter();
        $ids = $items->pluck('service_id')->filter()->unique()->values();
        $services = $ids->isEmpty()
            ? collect()
            : Service::whereIn('id', $ids)->get(self::SERVICE_COLUMNS)->keyBy('id');

        foreach ($items as $item) {
            $item->setAttribute('pricing', self::resolve($item, $item->service_id ? $services->get($item->service_id) : null));
        }
    }

    /**
     * Parse the optional (+₱extra) charge out of a size string (e.g. "2XL (+₱50.00)" -> 50.0).
     */
    public static function parseSizeExtra(?string $size): float
    {
        if (! $size) {
            return 0.0;
        }
        if (preg_match('/\(\+₱([0-9,.]+)\)/', $size, $matches)) {
            $num = (float) str_replace(',', '', $matches[1]);

            return max(0.0, round($num, 2));
        }

        return 0.0;
    }

    /**
     * Resolve selected add-ons against catalog design and parent service definitions.
     * Prevents duplicate double-charging if the same add-on is listed on both levels.
     *
     * @param  array<int, string|array{id?:string, name?:string, label?:string}>|null  $selected
     * @return array{items: list<array{name: string, price: float, level: string}>, total: float}
     */
    public static function resolveAddOns(?CatalogItem $item, ?Service $service, ?array $selected): array
    {
        if (empty($selected)) {
            return ['items' => [], 'total' => 0.0];
        }

        // Available design add-ons (from catalog_items.add_ons)
        $designAddOns = collect($item?->add_ons ?? [])
            ->filter(fn ($a) => ! empty($a['name']) && isset($a['price']))
            ->keyBy(fn ($a) => mb_strtolower(trim($a['name'])));

        // Available service add-ons (from service_pricing)
        $serviceAddOns = collect($service?->pricing ?? [])
            ->filter(fn ($p) => ! empty($p->label) && (float) $p->amount > 0)
            ->keyBy(fn ($p) => mb_strtolower(trim($p->label)));

        $resolved = [];
        $seen = [];

        foreach ($selected as $sel) {
            $key = is_array($sel) ? ($sel['name'] ?? $sel['label'] ?? null) : $sel;
            if (! is_string($key) || trim($key) === '') {
                continue;
            }
            $cleanKey = mb_strtolower(trim($key));
            if (isset($seen[$cleanKey])) {
                continue; // Do not allow duplicate double charge for same item
            }
            $seen[$cleanKey] = true;

            // Design-level add-on takes priority if defined on the design
            if ($designAddOns->has($cleanKey)) {
                $d = $designAddOns->get($cleanKey);
                $price = max(0.0, round((float) $d['price'], 2));
                $resolved[] = [
                    'name' => trim($d['name']),
                    'price' => $price,
                    'level' => 'design',
                ];
            } elseif ($serviceAddOns->has($cleanKey)) {
                $s = $serviceAddOns->get($cleanKey);
                $price = max(0.0, round((float) $s->amount, 2));
                $resolved[] = [
                    'name' => trim($s->label),
                    'price' => $price,
                    'level' => 'service',
                ];
            }
        }

        $total = round(array_sum(array_column($resolved, 'price')), 2);

        return ['items' => $resolved, 'total' => $total];
    }

    private static function result(string $mode, ?float $servicePrice, ?float $extra, ?float $amount, ?float $regularAmount): array
    {
        return [
            'mode' => $mode,
            'service_price' => $servicePrice !== null ? round($servicePrice, 2) : null,
            'extra_charge' => $extra,
            'amount' => $amount,
            'regular_amount' => $regularAmount,
            'needs_quote' => $amount === null,
        ];
    }
}
