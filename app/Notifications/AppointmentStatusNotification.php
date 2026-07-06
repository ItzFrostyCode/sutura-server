<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
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

    /**
     * Delivery channels — database + mail, unless this is a synthetic walk-in
     * placeholder address (no real customer inbox to deliver to).
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email && !str_starts_with($notifiable->email, 'walkin_')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    private function titles(): array
    {
        return [
            'confirmed' => 'Appointment Confirmed',
            'rescheduled' => 'Appointment Rescheduled',
            'cancelled' => 'Appointment Cancelled',
            'completed' => 'Appointment Completed',
            'in_progress' => 'Appointment In Progress',
            'no_show' => 'No-Show Recorded',
        ];
    }

    private function messages(): array
    {
        $scheduledAt = $this->appointment->scheduled_at
            ? \Carbon\Carbon::parse($this->appointment->scheduled_at)->format('M d, Y h:i A')
            : 'N/A';

        return [
            'confirmed' => 'Your appointment for ' . $scheduledAt . ' has been confirmed by the shop.',
            'rescheduled' => 'Your appointment has been rescheduled to ' . $scheduledAt . '.',
            'cancelled' => 'Your appointment for ' . $scheduledAt . ' has been cancelled.',
            'completed' => 'Your fitting/consultation appointment on ' . $scheduledAt . ' is now marked as completed.',
            'in_progress' => 'Your appointment is now in progress.',
            'no_show' => 'You were marked as a no-show for your appointment at ' . $scheduledAt . '.',
        ];
    }

    /**
     * Mail representation — reuses the same title/message copy as the in-app
     * notification so both channels stay in sync automatically.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->titles()[$this->statusType] ?? 'Appointment Update';
        $message = $this->messages()[$this->statusType] ?? 'Your appointment status has been updated.';

        $shop = $this->appointment->shop;
        $shopUrl = $shop?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000') . '/shop/' . $shop->slug) : null;

        $mail = (new MailMessage)
            ->subject($title . ' — ' . ($shop?->name ?? 'SUTURA'))
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($message);

        if ($shopUrl) {
            $mail->action('Visit ' . $shop->name, $shopUrl);
        }

        return $mail->line('Thank you for booking with us!');
    }

    public function toArray(object $notifiable): array
    {
        $titles = $this->titles();
        $messages = $this->messages();

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
