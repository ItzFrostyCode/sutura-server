<?php

namespace App\Notifications;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AppointmentBookedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Appointment $appointment;

    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    /**
     * Delivery channels — database only (in-app notification).
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Database payload.
     */
    public function toArray(object $notifiable): array
    {
        $scheduledAt = $this->appointment->scheduled_at
            ? Carbon::parse($this->appointment->scheduled_at)->format('M d, Y h:i A')
            : 'N/A';

        return [
            'type' => 'appointment_booked',
            'title' => 'New Appointment Booked',
            'message' => ($this->appointment->customer?->name ?? 'A customer').' booked an appointment for '.$scheduledAt.'.',
            'action_url' => '/dashboard/appointments',
            'appointment_id' => $this->appointment->id,
            'customer_name' => $this->appointment->customer?->name,
            'scheduled_at' => $this->appointment->scheduled_at,
        ];
    }
}
