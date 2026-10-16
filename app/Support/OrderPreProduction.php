<?php

namespace App\Support;

use App\Models\JobOrder;

/**
 * The seven saved steps before production, worked out from what is already recorded on the order. There is
 * nothing extra to save: leaving and coming back lands on the first step that is not finished.
 */
final class OrderPreProduction
{
    /** @return array{steps:list<array{key:string,label:string,done:bool,note:?string}>,current:?string} */
    public static function steps(JobOrder $job): array
    {
        $needMeasure = OrderReadiness::measurementsNeeded($job);
        $detailsDone = $job->specs_approved_at !== null
            && (! $needMeasure || $job->measurements_verified_at !== null)
            && OrderReadiness::customerItemsOk($job);

        $decisionNote = match ($job->customer_decision) {
            'changes_requested' => 'The customer asked for changes: '.($job->customer_decision_note ?: '—'),
            'declined' => 'The customer declined.',
            default => $job->quotation_sent_at && ! $job->customer_approved_at ? 'Waiting for the customer.' : null,
        };

        $paymentNote = null;
        if ($job->payment_terms_locked_at && ! OrderReadiness::depositMet($job)) {
            $pending = OrderReadiness::pendingVerification($job);
            $paymentNote = $pending > 0.005
                ? '₱'.number_format($pending, 2).' is waiting to be verified.'
                : 'Deposit of ₱'.number_format(OrderReadiness::requiredDeposit($job), 2).' not paid yet.';
        }

        $steps = [
            ['key' => 'request', 'label' => 'Request accepted', 'done' => $job->shop_accepted_at !== null,
                'note' => $job->shop_accepted_at ? null : 'The shop has not accepted this request yet.'],
            ['key' => 'details', 'label' => 'Measurements and details', 'done' => $detailsDone,
                'note' => $detailsDone ? null : 'Approve the design details'.($needMeasure ? ' and verify the measurements.' : '.')],
            ['key' => 'quotation', 'label' => 'Price sent', 'done' => $job->quotation_status === 'final' && $job->quotation_sent_at !== null,
                'note' => $job->quotation_status === 'estimate' ? 'Only an estimate so far.' : null],
            ['key' => 'decision', 'label' => 'Customer answer', 'done' => $job->customer_approved_at !== null, 'note' => $decisionNote],
            ['key' => 'payment', 'label' => 'Payment terms and deposit',
                'done' => $job->payment_terms_locked_at !== null && OrderReadiness::depositMet($job), 'note' => $paymentNote],
            ['key' => 'staff', 'label' => 'Tailor assigned', 'done' => $job->assignees()->exists(), 'note' => null],
            ['key' => 'start', 'label' => 'Production authorized', 'done' => $job->production_authorized_at !== null, 'note' => null],
        ];

        $current = collect($steps)->first(fn (array $s) => ! $s['done']);

        return ['steps' => $steps, 'current' => $current['key'] ?? null];
    }
}
