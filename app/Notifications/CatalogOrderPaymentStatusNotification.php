<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\CatalogOrder;

/**
 * Same gap as AppointmentPaymentStatusNotification, for the walk-in/RTW
 * Catalog Order payment path (CatalogOrderController::verifyPayment) —
 * this one has a real total_amount to reference.
 */
class CatalogOrderPaymentStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public CatalogOrder $order;
    public string $status; // 'paid' or 'rejected'

    public function __construct(CatalogOrder $order, string $status)
    {
        $this->order = $order;
        $this->status = $status;
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email && !str_starts_with($notifiable->email, 'walkin_')) {
            $channels[] = 'mail';
        }
        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $accepted = $this->status === 'paid';
        $amount = number_format((float) $this->order->total_amount, 2);
        $mail = (new MailMessage)
            ->subject(($accepted ? 'Payment Confirmed' : 'Payment Not Accepted') . ' — Order #' . $this->order->id)
            ->greeting('Hello ' . $notifiable->name . ',');

        if ($accepted) {
            $mail->line('Your payment of ₱' . $amount . ' for order #' . $this->order->id . ' has been confirmed. Thank you!');
        } else {
            $mail->line('Your payment of ₱' . $amount . ' for order #' . $this->order->id . ' could not be accepted.')
                ->line('Please get in touch with the shop or resubmit your payment.');
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        $accepted = $this->status === 'paid';
        return [
            'type' => 'catalog_order_payment_' . $this->status,
            'title' => $accepted ? 'Payment Confirmed' : 'Payment Not Accepted',
            'message' => $accepted
                ? 'Your payment for order #' . $this->order->id . ' has been confirmed.'
                : 'Your payment for order #' . $this->order->id . ' was not accepted.',
            'catalog_order_id' => $this->order->id,
        ];
    }
}
