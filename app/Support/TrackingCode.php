<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\JobOrderController;
use App\Models\JobOrder;
use App\Models\Store;

/** A globally unique tracking code for the public tracker: the shop's prefix and 6 random characters. */
class TrackingCode
{
    public static function make(?Store $store = null): string
    {
        $prefix = JobOrderController::generateStorePrefix($store);
        $charset = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        while (true) {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $charset[random_int(0, strlen($charset) - 1)];
            }
            // Ensure a combination of letters and numbers.
            if (! preg_match('/[0-9]/', $suffix) || ! preg_match('/[A-Z]/', $suffix)) {
                continue;
            }
            $code = "{$prefix}{$suffix}";
            if (! JobOrder::withTrashed()->where('tracking_code', $code)->exists()) {
                return $code;
            }
        }
    }
}
