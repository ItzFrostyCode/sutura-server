<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Notifications\AppointmentStatusNotification;
use Illuminate\Console\Command;

class ExpireUnansweredAppointments extends Command
{
    protected $signature = 'app:expire-unanswered-appointments';

    protected $description = 'Cancel online requests nobody answered before their time had passed, so they stop holding a slot and the customer is told.';

    public function handle(): int
    {
        $reason = 'The shop did not answer before the appointment time.';
        $count = 0;

        Appointment::where('status', 'pending')
            ->where('kind', '!=', 'production')
            ->where('scheduled_at', '<', now())
            ->with('customer')
            ->each(function (Appointment $appointment) use ($reason, &$count) {
                $appointment->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);
                $appointment->customer?->notify(new AppointmentStatusNotification($appointment, 'cancelled', $reason));
                $count++;
            });

        $this->info("Cancelled {$count} unanswered request(s).");

        return self::SUCCESS;
    }
}
