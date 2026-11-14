<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\Store;

/**
 * What rush adds to an order, by the shop's rush setting (stores.rush_fee_mode):
 *  - percent:   rush_fee_percent of the order's price before rush (default 30%)
 *  - per_piece: rush_fee_per_piece pesos for every piece
 * A repeat customer (3+ earlier orders at this shop) pays half, as a loyalty perk. One rule for the walk-in form and
 * the online order, so the number the customer sees is the number on the quotation and the deposit. The shop can
 * still change the rush line while quoting.
 */
final class RushFee
{
    public const REPEAT_CUSTOMER_ORDERS = 3;

    private static function share(Store $store, ?int $customerId): float
    {
        if (! $store->rush_available) {
            return 0.0;
        }

        return $customerId && JobOrder::where('store_id', $store->id)->where('customer_id', $customerId)->count() >= self::REPEAT_CUSTOMER_ORDERS
            ? 0.5 : 1.0;
    }

    public static function perPiece(Store $store): bool
    {
        return $store->rush_fee_mode === 'per_piece';
    }

    /** The percentage that applies to this customer (0 when the shop takes no rush, or charges per piece). */
    public static function percentFor(Store $store, ?int $customerId): float
    {
        return self::perPiece($store) ? 0.0 : (float) ($store->rush_fee_percent ?? 30) * self::share($store, $customerId);
    }

    /** The pesos per piece that apply to this customer (0 when the shop takes no rush, or charges a percentage). */
    public static function perPieceFor(Store $store, ?int $customerId): float
    {
        return self::perPiece($store) ? round((float) ($store->rush_fee_per_piece ?? 0) * self::share($store, $customerId), 2) : 0.0;
    }

    /** The rush fee in pesos for an order of $pieces pieces whose price before rush is $subtotal. */
    public static function amount(Store $store, ?int $customerId, float $subtotal, int $pieces = 1): float
    {
        if (self::perPiece($store)) {
            return round(self::perPieceFor($store, $customerId) * max(1, $pieces), 2);
        }

        return $subtotal > 0 ? round($subtotal * self::percentFor($store, $customerId) / 100, 2) : 0.0;
    }
}
