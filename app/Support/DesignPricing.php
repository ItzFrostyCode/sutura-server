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

    public const EXTRA = 'extra';

    public const QUOTE = 'quote';

    public const MODES = [self::SAME, self::EXTRA, self::QUOTE];

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
