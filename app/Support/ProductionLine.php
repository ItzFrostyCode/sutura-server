<?php

namespace App\Support;

use App\Models\JobOrder;
use Illuminate\Support\Facades\DB;

/**
 * The fixed line an order follows while it is being made, the same every time:
 *   Pattern making (a bulk order: Mass cutting) -> Cutting -> Sewing -> Quality check
 *   Repair: Repair -> Quality check
 * Next and Back move one step along it (OrderProductionController). Quality check is left only by passing it or by
 * recording a fix, so Next stops there on ordered orders. Orders that don't use the confirmation flow (and repairs)
 * have no quality-check result to record: after Quality check their Next goes to Ready for pick-up, then Completed.
 */
final class ProductionLine
{
    /** @return list<string> */
    public static function steps(JobOrder $job): array
    {
        return $job->isRepairOnly()
            ? ['in_repair', 'qc_check']
            : [$job->firstProductionTask(), 'cutting', 'sewing', 'qc_ironing'];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'pattern_making' => 'Pattern making', 'mass_cutting_printing' => 'Mass cutting', 'cutting' => 'Cutting', 'sewing' => 'Sewing',
            'in_repair' => 'Repair', 'qc_ironing', 'qc_check' => 'Quality check', 'ready_for_pickup' => 'Ready for pick-up',
            'completed' => 'Completed', 'design' => 'Design', 'final_adjustments' => 'Fixing', default => str_replace('_', ' ', $status),
        };
    }

    /** Where $status sits on the line (0-based), or null when it is not on it. Pattern making and Mass cutting are the same slot. */
    public static function index(JobOrder $job, string $status): ?int
    {
        if (in_array($status, ['pattern_making', 'mass_cutting_printing'], true)) {
            $status = $job->firstProductionTask();
        }
        $i = array_search($status, self::steps($job), true);

        return $i === false ? null : $i;
    }

    /** Quality check has no Next on ordered orders: it is passed or sent to be fixed. */
    public static function atQualityCheck(JobOrder $job): bool
    {
        return in_array($job->status, ['qc_ironing', 'qc_check'], true) && OrderReadiness::gated($job);
    }

    public static function next(JobOrder $job): ?string
    {
        $status = $job->status;
        if ($status === 'design') {
            return $job->firstProductionTask();
        }
        if ($status === 'queued' && $job->isRepairOnly()) {
            return 'in_repair';
        }
        if (! OrderReadiness::gated($job)) {
            if ($status === 'ready_for_pickup') {
                return 'completed';
            }
            if ($status === 'final_adjustments') {
                return 'qc_ironing';
            }
            if (in_array($status, ['qc_ironing', 'qc_check'], true)) {
                return 'ready_for_pickup';
            }
        }
        $i = self::index($job, $status);
        $steps = self::steps($job);

        return $i !== null && isset($steps[$i + 1]) ? $steps[$i + 1] : null;
    }

    /** One step back, only while the quality check has not passed and never past the first task. */
    public static function previous(JobOrder $job): ?string
    {
        if ($job->qc_passed_at !== null) {
            return null;
        }
        $i = self::index($job, $job->status);

        return $i !== null && $i > 0 ? self::steps($job)[$i - 1] : null;
    }

    /** Statuses a hold can be put on: the line, the quality check and fixing. */
    public static function holdable(JobOrder $job): bool
    {
        return self::index($job, $job->status) !== null
            || in_array($job->status, ['design', 'qc_ironing', 'qc_check', 'final_adjustments'], true);
    }

    /** Where a hold with no saved step (an older hold) goes back to. */
    public static function resumeFallback(JobOrder $job): string
    {
        $stage = DB::table('job_order_staff')->where('job_order_id', $job->id)->orderByDesc('assigned_at')->orderByDesc('id')->value('stage');
        $stage = $stage === 'pattern_making' ? $job->firstProductionTask() : $stage;
        if ($stage && in_array($stage, self::steps($job), true)) {
            return $stage;
        }
        if ($job->production_started_at) {
            return $job->firstProductionTask();
        }

        return $job->confirmed_at ? 'confirmed' : 'pending';
    }
}
