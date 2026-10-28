<?php

namespace App\Support;

use App\Models\JobOrder;

/**
 * The six steps of a request (status pending), worked out from what is already recorded on the order. There is
 * nothing extra to save: leaving and coming back lands on the first step that is not finished. The tailor and
 * materials checks belong to the Confirmed column, not here.
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
                : self::depositNote($job);
        }

        $steps = [
            ['key' => 'request', 'label' => 'Request accepted', 'done' => $job->shop_accepted_at !== null,
                'note' => $job->shop_accepted_at ? null : 'The shop has not accepted this request yet.'],
            ['key' => 'details', 'label' => 'Details & sizes', 'done' => $detailsDone,
                'note' => $detailsDone ? null : 'Approve the design details'.($needMeasure ? ' and verify the measurements.' : '.')],
            ['key' => 'quotation', 'label' => 'Price sent', 'done' => $job->quotation_status === 'final' && $job->quotation_sent_at !== null,
                'note' => $job->quotation_status === 'estimate' ? 'Only an estimate so far.' : null],
            ['key' => 'decision', 'label' => 'Customer answer', 'done' => $job->customer_approved_at !== null, 'note' => $decisionNote],
            ['key' => 'payment', 'label' => 'Deposit',
                'done' => $job->payment_terms_locked_at !== null && OrderReadiness::depositMet($job), 'note' => $paymentNote],
            ['key' => 'confirmed', 'label' => 'Order confirmed', 'done' => $job->confirmed_at !== null, 'note' => null],
        ];

        $current = collect($steps)->first(fn (array $s) => ! $s['done']);

        return ['steps' => $steps, 'current' => $current['key'] ?? null];
    }

    /** The deposit step's note: what is still to pay when part of it is already in. */
    private static function depositNote(JobOrder $job): string
    {
        $required = OrderReadiness::requiredDeposit($job);
        $paid = min($required, OrderReadiness::paidSoFar($job));

        return $paid > 0.005
            ? '₱'.number_format($paid, 2).' of the ₱'.number_format($required, 2).' deposit is paid; ₱'.number_format($required - $paid, 2).' to go.'
            : 'Deposit of ₱'.number_format($required, 2).' not paid yet.';
    }
}
