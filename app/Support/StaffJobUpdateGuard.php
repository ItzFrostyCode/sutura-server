<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\User;
use Carbon\Carbon;

/**
 * What plain staff may change through the generic PUT /jobs/{id}: production notes, the completion photo and
 * production-stage status moves (start work, quality check, fixing, fitting, handover, complete). Anything else
 * that would actually change the order -- prices, dates, pieces, branch, design, materials -- is refused.
 * Owners and branch managers are not limited here.
 */
final class StaffJobUpdateGuard
{
    public const MESSAGE = 'Staff can only update production.';

    /** Fields staff may always save. */
    private const FREE_FIELDS = ['notes', 'completion_photo_url'];

    /** Statuses staff may never move an order into: the set-up steps, cancelling and declining. */
    private const CLOSED_TO_STAFF = ['pending', 'confirmed', 'awaiting_materials', 'cancelled', 'rejected'];

    /** Owner and branch manager accounts are not "plain staff", even if they also hold the staff role. */
    public static function appliesTo(User $user): bool
    {
        return ! $user->hasRole('store_owner') && ! $user->hasRole('branch_manager');
    }

    /** True when the request tries to change something staff may not. @param array<string,mixed> $input the raw request body */
    public static function changesMoreThanProduction(JobOrder $job, array $input): bool
    {
        foreach ($input as $key => $value) {
            if (in_array($key, self::FREE_FIELDS, true)) {
                continue;
            }
            if ($key === 'status') {
                if ($value !== $job->status && in_array($value, self::CLOSED_TO_STAFF, true)) {
                    return true;
                }

                continue;
            }
            if (! self::same($job->getAttribute($key), $value)) {
                return true;
            }
        }

        return false;
    }

    private static function same(mixed $current, mixed $new): bool
    {
        $empty = fn ($v) => $v === null || $v === '' || $v === [];
        if ($empty($current) && $empty($new)) {
            return true;
        }
        if ($empty($current) || $empty($new)) {
            return false;
        }
        if ($current instanceof \DateTimeInterface) {
            try {
                return Carbon::parse($current)->format('Y-m-d H:i') === Carbon::parse((string) $new)->format('Y-m-d H:i');
            } catch (\Throwable) {
                return false;
            }
        }
        if (is_array($current) || is_array($new)) {
            return json_encode($current) === json_encode($new);
        }
        if (is_numeric($current) && is_numeric($new)) {
            return abs((float) $current - (float) $new) < 0.005;
        }

        return (string) $current === (string) $new;
    }
}
