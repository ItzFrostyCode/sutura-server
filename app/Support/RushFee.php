<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\Service;
use App\Models\Store;

/**
 * What rush adds to an order. The rule comes from the service when it has its own (services.rush_available: false =
 * no rush for it, true = its own fee), else from the shop (stores.rush_*):
 *  - percent:   that percent of the order's price before rush (the shop's default is 30%)
 *  - per_piece: that many pesos for every piece
 * A repeat customer (3+ earlier orders at this shop) pays half, as a loyalty perk. One rule for the walk-in form and
 * the online order, so the number the customer sees is the number on the quotation and the deposit. The shop can
 * still change the rush line while quoting.
 */
final class RushFee
{
    public const REPEAT_CUSTOMER_ORDERS = 3;

    public const MODES = ['percent', 'per_piece'];

    /**
     * The rush rule that applies: the service's own, else the shop's.
     *
     * @return array{available: bool, mode: string, percent: float, per_piece: float, source: string}
     */
    public static function rules(Store $store, ?Service $service = null): array
    {
        if ($service && $service->rush_available === false) {
            return ['available' => false, 'mode' => 'percent', 'percent' => 0.0, 'per_piece' => 0.0, 'source' => 'service'];
        }
        if ($service && $service->rush_available === true && in_array($service->rush_fee_mode, self::MODES, true)) {
            return [
                'available' => true,
                'mode' => $service->rush_fee_mode,
                'percent' => (float) ($service->rush_fee_percent ?? 0),
                'per_piece' => (float) ($service->rush_fee_per_piece ?? 0),
                'source' => 'service',
            ];
        }

        return [
            'available' => (bool) $store->rush_available,
            'mode' => $store->rush_fee_mode === 'per_piece' ? 'per_piece' : 'percent',
            'percent' => (float) ($store->rush_fee_percent ?? 30),
            'per_piece' => (float) ($store->rush_fee_per_piece ?? 0),
            'source' => 'shop',
        ];
    }

    public static function available(Store $store, ?Service $service = null): bool
    {
        return self::rules($store, $service)['available'];
    }

    private static function share(Store $store, ?int $customerId, ?Service $service): float
    {
        if (! self::available($store, $service)) {
            return 0.0;
        }

        return $customerId && JobOrder::where('store_id', $store->id)->where('customer_id', $customerId)->count() >= self::REPEAT_CUSTOMER_ORDERS
            ? 0.5 : 1.0;
    }

    public static function perPiece(Store $store, ?Service $service = null): bool
    {
        return self::rules($store, $service)['mode'] === 'per_piece';
    }

    /** The percentage that applies to this customer (0 when there is no rush, or it is charged per piece). */
    public static function percentFor(Store $store, ?int $customerId, ?Service $service = null): float
    {
        $r = self::rules($store, $service);

        return $r['mode'] === 'per_piece' ? 0.0 : $r['percent'] * self::share($store, $customerId, $service);
    }

    /** The pesos per piece that apply to this customer (0 when there is no rush, or it is a percentage). */
    public static function perPieceFor(Store $store, ?int $customerId, ?Service $service = null): float
    {
        $r = self::rules($store, $service);

        return $r['mode'] === 'per_piece' ? round($r['per_piece'] * self::share($store, $customerId, $service), 2) : 0.0;
    }

    /** The rush fee in pesos for an order of $pieces pieces whose price before rush is $subtotal. */
    public static function amount(Store $store, ?int $customerId, float $subtotal, int $pieces = 1, ?Service $service = null): float
    {
        if (self::perPiece($store, $service)) {
            return round(self::perPieceFor($store, $customerId, $service) * max(1, $pieces), 2);
        }

        return $subtotal > 0 ? round($subtotal * self::percentFor($store, $customerId, $service) / 100, 2) : 0.0;
    }
}
