<?php

namespace App\Support;

use App\Models\JobOrder;

/**
 * The drop-off check for work on the customer's own garment (repair, alteration, embroidery) requested online.
 *
 * Online, the customer only describes the garment; the shop sees it when it is brought in. So before any work:
 *   1. The shop checks it: "as described" (work may start) or "different" (what differs, photos, the new price per line).
 *   2. If different, the customer accepts the new plan and price, or declines and takes the garment back (nothing to pay).
 * A walk-in garment is checked at the counter when the order is written up, so this applies to online requests only.
 * Stored on custom_order_data['garment_check'] (no new table): result, note, photos, lines, total, decision, timestamps.
 */
final class GarmentCheck
{
    public const AS_DESCRIBED = 'as_described';

    public const DIFFERENT = 'different';

    /** Statuses the work moves into; none of them may start before the check is settled. */
    private const WORK = ['in_repair', 'qc_check', 'ready_for_pickup', 'completed'];

    public static function applies(JobOrder $job): bool
    {
        return $job->isRepairOnly() && $job->intake_channel === 'online';
    }

    /** @return array<string,mixed>|null */
    public static function of(JobOrder $job): ?array
    {
        $check = ((array) $job->custom_order_data)['garment_check'] ?? null;

        return is_array($check) ? $check : null;
    }

    /** True once the garment may be worked on: checked as described, or the customer accepted the new plan. */
    public static function settled(JobOrder $job): bool
    {
        if (! self::applies($job)) {
            return true;
        }
        $check = self::of($job);

        return $check !== null && (($check['result'] ?? null) === self::AS_DESCRIBED || ($check['decision'] ?? null) === 'accepted');
    }

    public static function awaitingCustomer(JobOrder $job): bool
    {
        $check = self::of($job);

        return self::applies($job) && $check && ($check['result'] ?? null) === self::DIFFERENT && empty($check['decision']);
    }

    /** The plain reason the work cannot start yet, or null. */
    public static function blocker(JobOrder $job, ?string $newStatus): ?string
    {
        if (! $newStatus || ! in_array($newStatus, self::WORK, true) || self::settled($job)) {
            return null;
        }

        return self::awaitingCustomer($job)
            ? 'The customer has not accepted the new price yet. Work starts after they accept.'
            : 'Check the garment first: confirm it matches the request, or send the customer what is different.';
    }

    /** What the customer sees on their order page (null when this order has no drop-off check). */
    public static function forCustomer(JobOrder $job): ?array
    {
        if (! self::applies($job)) {
            return null;
        }
        $check = self::of($job);

        return [
            'state' => match (true) {
                $check === null => 'waiting_for_garment',
                ($check['result'] ?? null) === self::AS_DESCRIBED => 'as_described',
                ($check['decision'] ?? null) === 'accepted' => 'accepted',
                ($check['decision'] ?? null) === 'declined' => 'declined',
                default => 'needs_answer',
            },
            'note' => $check['note'] ?? null,
            'photos' => $check['photos'] ?? [],
            'lines' => $check['lines'] ?? [],
            'total' => isset($check['total']) ? (float) $check['total'] : null,
            'previous_total' => isset($check['previous_total']) ? (float) $check['previous_total'] : null,
            'checked_at' => $check['checked_at'] ?? null,
        ];
    }
}
