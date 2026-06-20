<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\Appointment;

class AppointmentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Appointment $appointment;
    public string $statusType; // 'confirmed', 'rescheduled', 'cancelled', 'completed'

    public function __construct(Appointment $appointment, string $statusType)
    {
        $this->appointment = $appointment;
        $this->statusType = $statusType;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $scheduledAt = $this->appointment->scheduled_at
            ? \Carbon\Carbon::parse($this->appointment->scheduled_at)->format('M d, Y h:i A')
            : 'N/A';

        $titles = [
            'confirmed' => 'Appointment Confirmed',
            'rescheduled' => 'Appointment Rescheduled',
            'cancelled' => 'Appointment Cancelled',
            'completed' => 'Appointment Completed',
            'in_progress' => 'Appointment In Progress',
            'no_show' => 'No-Show Recorded',
        ];

        $messages = [
            'confirmed' => 'Your appointment for ' . $scheduledAt . ' has been confirmed by the shop.',
            'rescheduled' => 'Your appointment has been rescheduled to ' . $scheduledAt . '.',
            'cancelled' => 'Your appointment for ' . $scheduledAt . ' has been cancelled.',
            'completed' => 'Your fitting/consultation appointment on ' . $scheduledAt . ' is now marked as completed.',
            'in_progress' => 'Your appointment is now in progress.',
            'no_show' => 'You were marked as a no-show for your appointment at ' . $scheduledAt . '.',
        ];

        return [
            'type' => 'appointment_' . $this->statusType,
            'title' => $titles[$this->statusType] ?? 'Appointment Update',
            'message' => $messages[$this->statusType] ?? 'Your appointment status has been updated.',
            'action_url' => '/dashboard/appointments',
            'appointment_id' => $this->appointment->id,
            'scheduled_at' => $this->appointment->scheduled_at,
        ];
    }
}
