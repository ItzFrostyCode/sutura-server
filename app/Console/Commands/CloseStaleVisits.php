<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use Illuminate\Console\Command;

class CloseStaleVisits extends Command
{
    protected $signature = 'app:close-stale-visits';

    protected $description = 'Close visits nobody closed a day after they should have ended, and move fitting placeholders whose time passed to the next open day.';

    /** How long after a visit should have ended it is closed for the shop. */
    private const GRACE_HOURS = 24;

    public function handle(): int
    {
        $cutoff = now()->subHours(self::GRACE_HOURS);
        $noShow = $done = $moved = 0;

        // Approved, the customer never arrived: they didn't come. Arrived but never started: the visit happened.
        Appointment::where('status', 'confirmed')->where('kind', '!=', 'production')
            ->where('scheduled_at', '<', $cutoff)->with('store')->each(function (Appointment $a) use ($cutoff, &$noShow, &$done) {
                if ($a->endsAt()?->gt($cutoff)) {
                    return;
                }
                if ($a->checked_in_at) {
                    $a->update(['status' => 'completed', 'outcome' => 'completed']);
                    $done++;
                } else {
                    $a->update(['status' => 'no_show', 'outcome' => 'no_show']);
                    $noShow++;
                }
                $this->log($a, $a->status);
            });

        // Started and never finished: it was a consultation. A job order can still be made from it afterwards.
        Appointment::where('status', 'in_progress')->where('kind', '!=', 'production')
            ->where('scheduled_at', '<', $cutoff)->with('store')->each(function (Appointment $a) use ($cutoff, &$done) {
                if ($a->endsAt()?->gt($cutoff)) {
                    return;
                }
                $a->update(['status' => 'completed', 'outcome' => 'completed']);
                $this->log($a, 'completed');
                $done++;
            });

        // A fitting the job order made whose placeholder time passed unconfirmed: same row, next open day. No new rows, no spam.
        Appointment::where('status', 'pending')->where('kind', 'production')->where('scheduled_at', '<', now())->with('store')->each(function (Appointment $a) use (&$moved) {
            $at = now()->addDay()->setTime(10, 0);
            for ($i = 0; $i < 30 && $a->store?->closureTitleOn($at, $a->store_branch_id); $i++) {
                $at = $at->copy()->addDay();
            }
            $a->update(['scheduled_at' => $at]);
            $moved++;
        });

        $this->info("No-show {$noShow}, finished {$done}, fitting placeholders moved {$moved}.");

        return self::SUCCESS;
    }

    private function log(Appointment $a, string $status): void
    {
        $a->store?->auditLogs()->create([
            'user_id' => $a->store->owner_id,
            'action' => 'appointment_closed_automatically',
            'model_type' => Appointment::class,
            'model_id' => $a->id,
            'payload' => ['became' => $status, 'after_hours' => self::GRACE_HOURS],
            'ip_address' => null,
        ]);
    }
}
