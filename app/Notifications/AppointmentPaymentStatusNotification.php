<?php

namespace App\Notifications;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AppointmentController::verifyPayment had zero customer-facing signal on
 * either outcome — same gap as job order payment rejection, just for the
 * appointment deposit/receipt path instead. Appointment has no stored
 * amount field (unlike JobOrder/CatalogOrder), so the copy stays generic.
 */
class AppointmentPaymentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Appointment $appointment;

    public string $status; // 'paid' or 'rejected'

    public function __construct(Appointment $appointment, string $status)
    {
        $this->appointment = $appointment;
        $this->status = $status;
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email && ! str_starts_with($notifiable->email, 'walkin_')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    private function scheduledLabel(): string
    {
        return $this->appointment->scheduled_at
            ? Carbon::parse($this->appointment->scheduled_at)->format('M d, Y h:i A')
            : 'N/A';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $accepted = $this->status === 'paid';
        $mail = (new MailMessage)
            ->subject(($accepted ? 'Payment Confirmed' : 'Payment Not Accepted').' — Appointment on '.$this->scheduledLabel())
            ->greeting('Hello '.$notifiable->name.',');

        if ($accepted) {
            $mail->line('Your payment for your appointment on '.$this->scheduledLabel().' has been confirmed. Thank you!');
        } else {
            $mail->line('Your payment for your appointment on '.$this->scheduledLabel().' could not be accepted.')
                ->line('Please get in touch with the store or resubmit your payment.');
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        $accepted = $this->status === 'paid';
        $store = $this->appointment->store;

        return [
            'type' => 'appointment_payment_'.$this->status,
            'title' => $accepted ? 'Payment Confirmed' : 'Payment Not Accepted',
            'message' => $accepted
                ? 'Your payment for your appointment on '.$this->scheduledLabel().' has been confirmed.'
                : 'Your payment for your appointment on '.$this->scheduledLabel().' was not accepted.',
            'action_url' => '/account/appointments/'.$this->appointment->id,
            'appointment_id' => $this->appointment->id,
            'store' => $store ? [
                'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path,
            ] : null,
        ];
    }
}
