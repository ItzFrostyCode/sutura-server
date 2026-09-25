<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Appointment $appointment;

    public string $statusType; // 'confirmed', 'rescheduled', 'cancelled', 'completed', 'walk_in_preempted'

    public ?string $customMessage;

    // Which staff/owner made this change — lets the customer-facing
    // notification row show the actual person's avatar instead of always
    // falling back to the store logo. Optional since not every call site
    // (e.g. a system-triggered status change) has a human actor.
    public ?User $actor;

    public function __construct(Appointment $appointment, string $statusType, ?string $customMessage = null, ?User $actor = null)
    {
        $this->appointment = $appointment;
        $this->statusType = $statusType;
        $this->customMessage = $customMessage;
        $this->actor = $actor;
    }

    /**
     * Delivery channels — database + mail, unless this is a synthetic walk-in
     * placeholder address (no real customer inbox to deliver to).
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email && ! str_starts_with($notifiable->email, 'walkin_')) {
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
            'walk_in_preempted' => 'Appointment Slot Claimed by Walk-in Client',
        ];
    }

    private function messages(): array
    {
        if ($this->customMessage) {
            return [
                $this->statusType => $this->customMessage,
            ];
        }

        $scheduledAt = $this->appointment->scheduled_at
            ? Carbon::parse($this->appointment->scheduled_at)->format('M d, Y h:i A')
            : 'N/A';

        return [
            'confirmed' => 'Your appointment for '.$scheduledAt.' has been confirmed by the store.',
            'rescheduled' => 'Your appointment has been rescheduled to '.$scheduledAt.'.',
            'cancelled' => 'Your appointment for '.$scheduledAt.' has been cancelled.',
            'completed' => 'Your fitting/consultation appointment on '.$scheduledAt.' is now marked as completed.',
            'in_progress' => 'Your appointment is now in progress.',
            'no_show' => 'You were marked as a no-show for your appointment at '.$scheduledAt.'.',
            'walk_in_preempted' => 'Your requested appointment slot for '.$scheduledAt.' was claimed by an in-store walk-in client who arrived earlier. Please choose an alternative time slot.',
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

        $store = $this->appointment->store;
        $storeUrl = $store?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000').'/store/'.$store->slug) : null;

        $mail = (new MailMessage)
            ->subject($title.' — '.($store?->name ?? 'SUTURA'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line($message);

        if ($storeUrl) {
            $mail->action('Visit '.$store->name, $storeUrl);
        }

        return $mail->line('Thank you for booking with us!');
    }

    public function toArray(object $notifiable): array
    {
        $titles = $this->titles();
        $messages = $this->messages();
        $store = $this->appointment->store;

        return [
            'type' => 'appointment_'.$this->statusType,
            'title' => $titles[$this->statusType] ?? 'Appointment Update',
            'message' => $messages[$this->statusType] ?? 'Your appointment status has been updated.',
            // Was '/dashboard/appointments' — the store owner's own dashboard
            // route, unreachable (and meaningless) for the customer this
            // notification actually goes to.
            'action_url' => '/account/appointments/'.$this->appointment->id,
            'appointment_id' => $this->appointment->id,
            'scheduled_at' => $this->appointment->scheduled_at,
            'store' => $store ? [
                'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path,
            ] : null,
            'actor' => $this->actor ? [
                'id' => $this->actor->id, 'name' => $this->actor->name, 'profile_picture' => $this->actor->profile_picture,
            ] : null,
        ];
    }
}
