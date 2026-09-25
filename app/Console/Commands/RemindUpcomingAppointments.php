<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Notifications\AppointmentReminderNotification;
use Illuminate\Console\Command;

class RemindUpcomingAppointments extends Command
{
    protected $signature = 'app:remind-upcoming-appointments';

    protected $description = 'Email/notify customers ~24h ahead of a still-pending/confirmed appointment — the "customer forgot their fitting" pain point named in tailoring-store interview research.';

    public function handle(): int
    {
        // A 2-hour window, not an exact "= tomorrow same minute" match —
        // this command runs hourly (see routes/console.php), so a wide
        // enough window guarantees every appointment gets exactly one pass
        // through it without needing to run every minute. reminder_sent_at
        // being null is what actually prevents a duplicate send.
        $windowStart = now()->addHours(23);
        $windowEnd = now()->addHours(25);

        $appointments = Appointment::whereIn('status', ['pending', 'confirmed'])
            ->whereNull('reminder_sent_at')
            ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
            ->with('customer', 'store')
            ->get();

        $sent = 0;
        foreach ($appointments as $appointment) {
            if (! $appointment->customer) {
                continue;
            }
            $appointment->customer->notify(new AppointmentReminderNotification($appointment));
            $appointment->forceFill(['reminder_sent_at' => now()])->save();
            $sent++;
        }

        $this->info("Sent {$sent} appointment reminder(s).");

        return self::SUCCESS;
    }
}
