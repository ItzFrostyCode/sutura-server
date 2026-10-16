<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\OrderCorrection;

/**
 * The customer's view of an order in five plain stages, worked out from the real internal status, the
 * quotation/payment facts and any open correction. One place builds it, so the signed-in customer, the guest
 * tracker and the order list always agree. Internal tasks (design, cutting, sewing, quality check...) stay with
 * the shop; the customer sees where the order is and what, if anything, they need to do.
 *
 * The bar never moves backwards: a problem found at the final check or a change request is a note inside
 * "Being made"; an adjustment after the customer's own fitting is a note inside "Ready".
 */
final class OrderProgress
{
    public const STAGES = [
        ['key' => 'request', 'label' => 'Request'],
        ['key' => 'confirmed', 'label' => 'Confirmed'],
        ['key' => 'making', 'label' => 'Being made'],
        ['key' => 'ready', 'label' => 'Ready'],
        ['key' => 'done', 'label' => 'Done'],
    ];

    /**
     * @return array{stages:list<array{key:string,label:string,state:string}>,current:?string,closed:?string,on_hold:bool,headline:string,detail:?string,action:?array{key:string,label:string}}
     */
    public static function for(JobOrder $job, bool $forGuest = false): array
    {
        $closed = match (true) {
            $job->status === 'cancelled' && $job->cancellation_reason === 'quotation_declined' => 'declined',
            $job->status === 'cancelled' => 'cancelled',
            $job->status === 'rejected' => 'rejected',
            default => null,
        };
        $onHold = $job->status === 'on_hold';
        $index = $closed ? null : self::index($job);

        [$headline, $detail, $action] = self::words($job, $closed, $onHold);

        return [
            'stages' => array_map(fn (array $stage, int $i) => $stage + ['state' => match (true) {
                $index === null => 'upcoming',
                $job->status === 'completed' => 'done',
                $i < $index => 'done',
                $i === $index => 'current',
                default => 'upcoming',
            }], self::STAGES, array_keys(self::STAGES)),
            'current' => $index === null ? null : self::STAGES[$index]['key'],
            'closed' => $closed,
            'on_hold' => $onHold,
            'headline' => $headline,
            'detail' => $detail,
            // A guest can look but not act, so they are never told to "approve" or "pay" here.
            'action' => $forGuest ? null : $action,
        ];
    }

    private static function openKinds(JobOrder $job): array
    {
        return $job->corrections()->whereIn('status', OrderCorrection::BLOCKING_STATUSES)->pluck('kind')->all();
    }

    private static function index(JobOrder $job): int
    {
        $status = $job->status;
        $flow = OrderReadiness::gated($job);

        return match (true) {
            $status === 'on_hold' => $job->production_started_at ? 2 : ($job->confirmed_at ? 1 : 0),
            $status === 'pending' => 0,
            in_array($status, ['confirmed', 'awaiting_materials', 'queued'], true) => 1,
            $status === 'completed' => 4,
            in_array($status, ['ready_for_pickup', 'balance_handover'], true) => 3,
            // Flow orders: the customer's fitting is part of "Ready". Older orders did the fitting before the
            // final check, so for them it is still part of "Being made".
            $status === 'ready_for_fitting' => $flow ? 3 : 2,
            $status === 'final_adjustments' => $flow && in_array('fitting_issue', self::openKinds($job), true) ? 3 : 2,
            default => 2,
        };
    }

    private static function money(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }

    /** @return array{0:string,1:?string,2:?array{key:string,label:string}} */
    private static function words(JobOrder $job, ?string $closed, bool $onHold): array
    {
        if ($closed) {
            return [match ($closed) {
                'declined' => 'You declined this price.',
                'rejected' => 'The shop could not take this order.',
                default => 'This order was cancelled.',
            }, null, null];
        }
        if ($onHold) {
            return ['Your order is on hold.', 'The shop paused it for now and will contact you.', null];
        }

        $flow = OrderReadiness::gated($job);
        $balance = (float) $job->balance;

        return match ($job->status) {
            'pending' => $flow ? self::pending($job) : ['The shop is checking your order.', null, null],
            'confirmed' => ['Your order is confirmed.', 'Work will start soon.', null],
            'awaiting_materials' => ['Your order is confirmed.', 'We are waiting for materials before we start.', null],
            'queued' => ['Your repair is in line.', 'The shop will start on it soon.', null],
            'qc_ironing', 'qc_check' => ['Final check.', 'The shop is checking your garment before it is ready.', null],
            'final_adjustments' => $flow && in_array('fitting_issue', self::openKinds($job), true)
                ? ['We are adjusting your garment.', 'This is after your fitting. You will be told when it is ready again.', null]
                : ['We are fixing a small detail.', 'It is part of the final check.', null],
            'ready_for_fitting' => $flow
                ? ['Your fitting is in progress.', null, null]
                : ['The shop is making your garment.', 'Fitting and adjustments are part of it.', null],
            'ready_for_pickup' => self::ready($job, $flow, $balance),
            'balance_handover' => [
                'You are paying the balance and taking your garment.',
                $balance > 0.005 ? 'Balance to pay: '.self::money($balance).'.' : null,
                null,
            ],
            'completed' => ['Your order is complete.', 'Thank you!', null],
            default => ['The shop is making your garment.', null, null],
        };
    }

    /** @return array{0:string,1:?string,2:?array{key:string,label:string}} */
    private static function pending(JobOrder $job): array
    {
        if (! $job->shop_accepted_at) {
            return ['The shop is reviewing your request.', 'You will be told when your price is ready.', null];
        }
        if ($job->quotation_status !== 'final') {
            return ['The shop is preparing your price.', 'You will be told when it is ready.', null];
        }
        if (! $job->customer_approved_at) {
            if ($job->customer_decision === 'changes_requested') {
                return ['You asked for changes.', 'The shop will send you a new price.', null];
            }
            if ($job->quotation_valid_until && $job->quotation_valid_until->lt(today())) {
                return ['This price is no longer valid.', 'Ask the shop for a new one.', null];
            }

            return [
                'Your price is ready.',
                'Total '.self::money((float) $job->total_amount).'. '.OrderReadiness::termsText($job)
                    .($job->quotation_valid_until ? ' Valid until '.$job->quotation_valid_until->format('M j').'.' : ''),
                ['key' => 'review_price', 'label' => 'Review and answer'],
            ];
        }
        if (! OrderReadiness::depositMet($job)) {
            if (OrderReadiness::pendingVerification($job) > 0.005) {
                return ['You approved the price.', 'We received your payment proof. The shop is checking it.', null];
            }

            return [
                'You approved the price.',
                'Please pay '.self::money(OrderReadiness::requiredDeposit($job)).' so we can start.',
                ['key' => 'pay_deposit', 'label' => 'Pay the deposit'],
            ];
        }

        return ['You approved the price.', 'Waiting for the shop to confirm your order.', null];
    }

    /** @return array{0:string,1:?string,2:?array{key:string,label:string}} */
    private static function ready(JobOrder $job, bool $flow, float $balance): array
    {
        $bring = $balance <= 0.005
            ? 'Your order is fully paid.'
            : (OrderReadiness::paidSoFar($job) > 0.005
                ? 'Please bring the balance: '.self::money($balance).'.'
                : 'Please bring the full payment: '.self::money($balance).'.');

        if ($flow && OrderReadiness::fittingNeeded($job)) {
            return ['Your garment is ready.', 'Please come in for the fitting. '.$bring, ['key' => 'come_in', 'label' => 'Come in for the fitting']];
        }

        return ['Your garment is ready for pick-up.', $bring, ['key' => 'come_in', 'label' => 'Come in to pick it up']];
    }
}
