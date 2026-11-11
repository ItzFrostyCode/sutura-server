<?php

namespace App\Support;

use App\Models\JobOrder;

/**
 * What the public tracker may say (DSD-02 WF-8, B5-O4, B8-O06): only the customer-visible stage and the day it was
 * last updated. The stage names are the approved ones (B6-O05): Accepted, In Production (Cutting, Stitching),
 * Quality Check, Customer Fitting, Adjustment, Ready for Customer, Completed.
 *
 * A Job Order has a public stage only once it is authorized to enter production (WD-20): a preliminary order waiting
 * for its quotation, or one closed before production, answers exactly like an unknown code.
 */
final class PublicStage
{
    private const MAP = [
        'confirmed' => 'Accepted',
        'awaiting_materials' => 'Accepted',
        'design' => 'In Production',
        'pattern_making' => 'In Production',
        'queued' => 'In Production',
        'in_repair' => 'In Production',
        'mass_cutting_printing' => 'In Production · Cutting',
        'cutting' => 'In Production · Cutting',
        'sewing' => 'In Production · Stitching',
        'qc_ironing' => 'Quality Check',
        'qc_check' => 'Quality Check',
        'ready_for_fitting' => 'Customer Fitting',
        'final_adjustments' => 'Adjustment',
        'ready_for_pickup' => 'Ready for Customer',
        'balance_handover' => 'Ready for Customer',
        'completed' => 'Completed',
        'on_hold' => 'On hold',
    ];

    /** The public stage, or null when the order must not be visible through a tracking code. */
    public static function of(JobOrder $job): ?string
    {
        // On hold before production was ever authorized is still a preliminary record.
        if ($job->status === 'on_hold' && ! $job->confirmed_at && $job->production_started_at === null) {
            return null;
        }

        return self::MAP[$job->status] ?? null;
    }
}
