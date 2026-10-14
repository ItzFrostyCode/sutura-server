<?php

namespace App\Support;

use App\Models\JobOrder;

/**
 * A SUGGESTED refund for a cancelled order, with its basis shown. The owner enters what the shop
 * already spent that cannot be recovered (costs already incurred), and may change the final amount
 * (a reason is then required). It is never a legal rule: shop-caused problems and other consumer
 * remedies are assessed separately, so "production started" does not mean "no refund".
 * SUTURA keeps no material, supplier or stock records to compute this from.
 */
final class OrderRefundSuggestion
{
    public static function forJob(JobOrder $job, float $nonRefundable = 0.0): array
    {
        $paid = OrderReadiness::paidSoFar($job);
        $costs = min($paid, max(0.0, round($nonRefundable, 2)));

        $stage = $job->production_started_at ? 'production_started' : ($costs > 0 ? 'costs_incurred' : 'no_costs_yet');

        return [
            'stage' => $stage,
            'paid_amount' => $paid,
            'non_refundable_amount' => $costs,
            'suggested_amount' => round(max(0.0, $paid - $costs), 2),
            'basis' => ['paid' => $paid, 'non_refundable' => $costs],
            'policy' => $job->store?->cancellationPolicyText(),
        ];
    }
}
