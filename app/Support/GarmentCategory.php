<?php

namespace App\Support;

/**
 * job_orders.garment_category / appointments.garment_category values.
 *
 * The column is a plain nullable string (no DB enum), so this list is the
 * only gate — shared by the job-order and appointment form requests.
 */
final class GarmentCategory
{
    /** Broad structural classes offered on the New Job Order form. */
    public const STANDARD = ['top', 'bottom', 'full_set', 'gown_dress', 'outerwear', 'others'];

    /** Set automatically for Alteration & Repair orders (drives repair-specific rules). */
    public const SYSTEM = ['alteration_repair'];

    /** Older garment-by-name values: still on existing orders/appointments, still accepted on update. */
    public const LEGACY = ['barong', 'gown', 'suit', 'filipiniana', 'uniform', 'lab_gown', 'scrub_suit', 'corporate_wear'];

    /** @return list<string> */
    public static function all(): array
    {
        return [...self::STANDARD, ...self::SYSTEM, ...self::LEGACY];
    }
}
