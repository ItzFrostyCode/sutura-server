<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * One order-number series per store, shared by every kind of order (walk-in
 * sales and job orders, online or walk-in): ORD-0001, ORD-0002, … No year in
 * it, so a number never "resets" and two different orders never look alike.
 * Counts soft-deleted rows too, so a number is never issued twice.
 */
class OrderNumber
{
    public const PREFIX = 'ORD-';

    public static function next(int $storeId): string
    {
        $max = 0;

        foreach (['job_orders', 'catalog_orders'] as $table) {
            DB::table($table)
                ->where('store_id', $storeId)
                ->where(function ($q) {
                    $q->where('order_number', 'like', 'ORD-%')->orWhere('order_number', 'like', 'JO-%');
                })
                ->pluck('order_number')
                ->each(function (string $number) use (&$max) {
                    if (preg_match('/^(?:ORD|JO)-(\d+)$/', $number, $m)) {
                        $max = max($max, (int) $m[1]);
                    }
                });
        }

        return self::PREFIX.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
