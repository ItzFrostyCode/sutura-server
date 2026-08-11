<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Appointment;

/**
 * The "customer forgot their fitting" pain point named directly in the
 * tailoring-shop interview research — sent ~24h ahead of a still-pending/
 * confirmed appointment by App\Console\Commands\RemindUpcomingAppointments.
 * Mirrors AppointmentStatusNotification's channel/copy structure, but this
 * one isn't triggered by a status change.
 */
class AppointmentReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Appointment $appointment;

    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email && !str_starts_with($notifiable->email, 'walkin_')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    private function scheduledLabel(): string
    {
        return $this->appointment->scheduled_at
            ? \Carbon\Carbon::parse($this->appointment->scheduled_at)->format('M d, Y h:i A')
            : 'N/A';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $shop = $this->appointment->shop;
        $shopUrl = $shop?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000') . '/shop/' . $shop->slug) : null;
        $type = ucfirst($this->appointment->appointment_type ?? 'appointment');

        $mail = (new MailMessage)
            ->subject('Reminder: Your ' . $type . ' Tomorrow — ' . ($shop?->name ?? 'SUTURA'))
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('This is a friendly reminder that you have a ' . strtolower($type) . ' appointment tomorrow, ' . $this->scheduledLabel() . '.');

        if ($shop?->address) {
            $mail->line('Location: ' . $shop->address);
        }

        if ($shopUrl) {
            $mail->action('View Appointment', $shopUrl);
        }

        return $mail->line('See you soon!');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'appointment_reminder',
            'title' => 'Appointment Reminder',
            'message' => 'Reminder: your appointment is scheduled for ' . $this->scheduledLabel() . '.',
            'action_url' => '/dashboard/appointments',
            'appointment_id' => $this->appointment->id,
            'scheduled_at' => $this->appointment->scheduled_at,
        ];
    }
}
