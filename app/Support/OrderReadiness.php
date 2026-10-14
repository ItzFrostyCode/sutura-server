<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\OrderCorrection;

/**
 * Single source of truth for the ordering flow's gates. JobOrderController::update() and the
 * order-flow controllers all call this, so the checklist the dashboard shows and the rules the API
 * enforces cannot drift apart.
 *
 * Flow 2 (set-up): what must be true before an order is CONFIRMED, and before work can START.
 * Flow 3 (delivery): In production -> Quality check -> Ready for final fitting / collection ->
 *   Customer fitting / inspection -> Adjustment / correction -> Balance payment & handover -> Completed.
 *
 * Only orders with uses_confirmation_flow are gated; older orders and repairs behave as before.
 */
final class OrderReadiness
{
    /** Statuses after quality check has passed. */
    private const AFTER_QC = ['ready_for_pickup', 'ready_for_fitting', 'balance_handover'];

    public static function gated(JobOrder $job): bool
    {
        return $job->uses_confirmation_flow && ! $job->isRepairOnly();
    }

    /** Verified money in, net of any discount (same formula as everywhere else: total - balance - discount). */
    public static function paidSoFar(JobOrder $job): float
    {
        return max(0.0, round((float) $job->total_amount - (float) $job->balance - (float) $job->discount_amount, 2));
    }

    public static function requiredDeposit(JobOrder $job): float
    {
        $due = max(0.0, (float) $job->total_amount - (float) $job->discount_amount);

        return round($job->requiredDepositFraction() * $due, 2);
    }

    public static function pendingVerification(JobOrder $job): float
    {
        return round((float) $job->payments()->whereNull('verified_at')->whereNull('rejected_at')->sum('amount'), 2);
    }

    public static function depositMet(JobOrder $job): bool
    {
        $required = self::requiredDeposit($job);

        return $required <= 0 || self::paidSoFar($job) + 0.005 >= $required;
    }

    public static function measurementsNeeded(JobOrder $job): bool
    {
        return ($job->measurement_requirement ?? 'shop') !== 'none';
    }

    public static function customerItemsApplicable(JobOrder $job): bool
    {
        return $job->material_source === 'customer_supplied';
    }

    /** Flow 2 step 3: the customer's own fabric has to be judged suitable before the price is final. */
    public static function customerItemsOk(JobOrder $job): bool
    {
        return ! self::customerItemsApplicable($job) || $job->customer_items_assessment === 'suitable';
    }

    /** Flow 2 step 7: the shop has confirmed the materials are ready (a yes/no, not a stock record). */
    public static function materialsReady(JobOrder $job): bool
    {
        return $job->materials_verified_at !== null && self::customerItemsOk($job);
    }

    public static function blockingCorrections(JobOrder $job): int
    {
        return $job->corrections()->whereIn('status', OrderCorrection::BLOCKING_STATUSES)->count();
    }

    public static function handedOver(JobOrder $job): int
    {
        return (int) $job->handovers()->sum('quantity');
    }

    public static function fittingNeeded(JobOrder $job): bool
    {
        return ($job->fitting_requirement ?? 'optional') !== 'none';
    }

    /** @return list<array{key:string,message:string}> */
    public static function confirmationBlockers(JobOrder $job): array
    {
        $out = [];
        if ($job->quotation_status !== 'final') {
            $out[] = ['key' => 'quotation', 'message' => 'The quotation is still an estimate. Finalize it first.'];
        }
        if (! $job->specs_approved_at) {
            $out[] = ['key' => 'specs', 'message' => 'The design and specifications are not approved yet.'];
        }
        if (! $job->customer_approved_at) {
            $out[] = ['key' => 'approval', 'message' => 'The customer has not approved the final quotation yet.'];
        }
        if (! self::depositMet($job)) {
            $pending = self::pendingVerification($job);
            $out[] = ['key' => 'deposit', 'message' => 'The required deposit of ₱'.number_format(self::requiredDeposit($job), 2).' is not verified yet'
                .($pending > 0 ? ' (₱'.number_format($pending, 2).' is waiting for verification).' : '.')];
        }

        return $out;
    }

    /** @return list<array{key:string,message:string}> */
    public static function startBlockers(JobOrder $job): array
    {
        $out = [];
        if (! self::materialsReady($job)) {
            $out[] = ['key' => 'materials', 'message' => self::customerItemsOk($job)
                ? 'The materials are not marked ready yet.'
                : 'The customer\'s own materials are not assessed as suitable yet.'];
        }
        if (self::measurementsNeeded($job) && ! $job->measurements_verified_at) {
            $out[] = ['key' => 'measurements', 'message' => 'The measurements are not verified yet.'];
        }
        if (! $job->assignees()->exists()) {
            $out[] = ['key' => 'tailor', 'message' => 'No tailor is assigned to this order yet.'];
        }

        return $out;
    }

    /** A held order resumes from where it really was, so a hold can't be used to skip the gates. */
    private static function effectiveStatus(JobOrder $job, string $old): string
    {
        if ($old !== 'on_hold') {
            return $old;
        }

        return $job->production_started_at ? 'in_production' : ($job->confirmed_at ? 'confirmed' : 'pending');
    }

    /** @return array{message:string,blockers:list<array{key:string,message:string}>}|null */
    public static function transitionError(JobOrder $job, string $old, string $new): ?array
    {
        if (! self::gated($job)) {
            return null;
        }
        $from = self::effectiveStatus($job, $old);
        $strict = $old !== 'on_hold';
        $err = fn (string $message, array $blockers = []) => ['message' => $message, 'blockers' => $blockers];
        $tasks = JobOrder::PRODUCTION_TASKS;

        // ---- Flow 2: confirming, and waiting for materials ----
        if ($new === 'confirmed') {
            if ($from === 'pending') {
                $b = self::confirmationBlockers($job);

                return $b ? $err('This order cannot be confirmed yet: '.$b[0]['message'], $b) : null;
            }
            if ($from === 'awaiting_materials' && ! self::materialsReady($job)) {
                return $err('Mark the materials ready first.');
            }

            return null;
        }
        if ($new === 'awaiting_materials') {
            return $from === 'confirmed' ? null : $err('Only a confirmed order can wait for materials.');
        }

        // ---- Flow 2: starting production ----
        if (JobOrder::startsProduction($new)) {
            if ($from === 'pending') {
                return $err('Confirm the order (approved quotation and verified deposit) before starting production.', self::confirmationBlockers($job));
            }
            if (in_array($from, ['confirmed', 'awaiting_materials'], true)) {
                if (! in_array($new, $tasks, true)) {
                    return $err('Start production first: choose the first production task.');
                }
                $b = self::startBlockers($job);
                if ($from === 'awaiting_materials') {
                    $b = array_values(array_filter($b, fn ($x) => $x['key'] !== 'materials'));
                    array_unshift($b, ['key' => 'materials', 'message' => 'The order is still waiting for materials.']);
                }

                return $b ? $err('Production cannot start yet: '.$b[0]['message'], $b) : null;
            }
        } elseif ($new === 'pending' && $job->production_started_at) {
            return $err('Production has already started, so this order cannot go back to pending.');
        }

        // ---- Flow 3: production -> quality check -> fitting / collection -> handover -> completed ----
        $open = self::blockingCorrections($job);
        $openMsg = "Finish the {$open} open correction".($open === 1 ? '' : 's').' first.';

        switch (true) {
            case in_array($new, $tasks, true):
                return in_array($from, [...self::AFTER_QC, 'completed'], true)
                    ? $err('This order already passed quality check. Record a correction to send it back.') : null;

            case $new === 'qc_ironing':
                if (in_array($from, self::AFTER_QC, true)) {
                    return $err('This order already passed quality check. Record a correction to send it back.');
                }

                return $from === 'final_adjustments' && $open > 0 ? $err($openMsg) : null;

            case $new === 'ready_for_pickup':
                if (! $job->qc_passed_at) {
                    return $err('Record the quality check as passed first.');
                }

                return $open > 0 ? $err($openMsg) : null;

            case $new === 'ready_for_fitting':
                if (! $job->qc_passed_at) {
                    return $err('Record the quality check as passed first.');
                }
                if (! self::fittingNeeded($job)) {
                    return $err('This order does not need a final fitting. Move it to Balance Payment & Handover instead.');
                }

                return $strict && $from !== 'ready_for_pickup' ? $err('The customer fitting follows "Ready for Final Fitting / Collection".') : null;

            case $new === 'final_adjustments':
                return $open === 0
                    ? $err('Record what needs correcting first: a failed quality check, a fitting issue, or a change request.') : null;

            case $new === 'balance_handover':
                if (! $job->qc_passed_at) {
                    return $err('Record the quality check as passed first.');
                }
                if ($open > 0) {
                    return $err($openMsg);
                }
                if ($strict && ! in_array($from, ['ready_for_pickup', 'ready_for_fitting'], true)) {
                    return $err('Handover follows "Ready for Final Fitting / Collection" or the fitting.');
                }
                if (($job->fitting_requirement ?? '') === 'required' && ! $job->fitting_passed_at && $from !== 'ready_for_fitting') {
                    return $err('A final fitting is required for this order. Complete the fitting first.');
                }

                return null;

            case $new === 'completed':
                if ($strict && $from !== 'balance_handover') {
                    return $err('Move the order to Balance Payment & Handover first.');
                }
                if ($open > 0) {
                    return $err($openMsg);
                }
                $given = self::handedOver($job);
                if ($given < (int) $job->quantity) {
                    return $err("Record the handover of every garment first ({$given} of {$job->quantity} handed over).");
                }

                return null;
        }

        return null;
    }

    /**
     * The shop says the materials are ready ("ready") or still on the way ("waiting"). This is a yes/no
     * sign-off, not a stock record. Flips confirmed <-> awaiting_materials. Returns the new status, or null.
     */
    public static function setMaterials(JobOrder $job, string $state, ?string $note, int $userId): ?string
    {
        $ready = $state === 'ready';
        $job->forceFill([
            'materials_verified_at' => $ready ? now() : null,
            'materials_verified_by' => $ready ? $userId : null,
            'materials_note' => $note,
        ])->save();

        return self::syncAwaitingMaterials($job, $state === 'waiting');
    }

    /** confirmed -> awaiting_materials when the shop flagged waiting; awaiting_materials -> confirmed once ready. */
    public static function syncAwaitingMaterials(JobOrder $job, bool $flaggedWaiting = false): ?string
    {
        if (! self::gated($job)) {
            return null;
        }
        if ($job->status === 'confirmed' && $flaggedWaiting) {
            $job->update(['status' => 'awaiting_materials']);

            return 'awaiting_materials';
        }
        if ($job->status === 'awaiting_materials' && self::materialsReady($job)) {
            $job->update(['status' => 'confirmed']);

            return 'confirmed';
        }

        return null;
    }
}
