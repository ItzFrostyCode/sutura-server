<?php

namespace App\Support;

use App\Models\JobOrder;
use Illuminate\Support\Carbon;

/**
 * "Can we actually finish by this date?" -- facts for the owner, not an automatic decision.
 * Shows the branch's open orders, how many are due around the same date, and, per planned
 * tailor, their other open orders and any that land within a day of this deadline.
 */
final class OrderCapacity
{
    private const TERMINAL = ['completed', 'cancelled', 'rejected'];

    public static function forJob(JobOrder $job): array
    {
        $open = fn () => JobOrder::where('store_id', $job->store_id)
            ->where('id', '!=', $job->id)
            ->whereNotIn('status', self::TERMINAL)
            ->when($job->store_branch_id, fn ($q) => $q->where('store_branch_id', $job->store_branch_id));

        $due = $job->due_date ? Carbon::parse($job->due_date) : null;

        $tailors = $job->assignees()->with('user:id,name')->get()->map(function ($a) use ($job, $due) {
            $others = JobOrder::where('store_id', $job->store_id)
                ->where('id', '!=', $job->id)
                ->whereNotIn('status', self::TERMINAL)
                ->where(function ($q) use ($a) {
                    $q->whereHas('assignees', fn ($x) => $x->where('user_id', $a->user_id))
                        ->orWhereHas('staffStages', fn ($x) => $x->where('users.id', $a->user_id)->whereNull('job_order_staff.completed_at'));
                })
                ->get(['id', 'order_number', 'due_date']);

            $conflicts = $due
                ? $others->filter(fn ($o) => $o->due_date && abs(Carbon::parse($o->due_date)->diffInDays($due, false)) <= 1)
                : collect();

            return [
                'user_id' => $a->user_id,
                'name' => $a->user?->name,
                'open_orders' => $others->count(),
                'conflicts' => $conflicts->map(fn ($o) => ['order_number' => $o->order_number, 'due_date' => $o->due_date?->toDateString()])->values()->all(),
            ];
        })->values()->all();

        return [
            'branch_open_orders' => $open()->count(),
            'due_within_2_days' => $due
                ? $open()->whereBetween('due_date', [$due->copy()->subDays(2)->toDateString(), $due->copy()->addDays(2)->toDateString()])->count()
                : null,
            'tailors' => $tailors,
            'has_conflict' => collect($tailors)->contains(fn ($t) => ! empty($t['conflicts'])),
        ];
    }
}
