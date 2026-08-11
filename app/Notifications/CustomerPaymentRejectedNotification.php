<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\JobOrder;
use App\Models\Payment;

/**
 * PaymentRejectedNotification only ever reaches the shop owner (and only
 * when a branch_manager did the rejecting) — the customer whose own payment
 * got rejected had no notification at all, in-app or email, and no way to
 * learn why short of asking the shop directly. Separate class, not a reuse
 * of PaymentRejectedNotification, because the audience/tone/action_url all
 * differ: that one is an owner-oversight alert, this is a customer-facing
 * "please resubmit" message that surfaces the staff-entered reason.
 */
class CustomerPaymentRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;
    public Payment $payment;
    public string $reason;

    public function __construct(JobOrder $jobOrder, Payment $payment, string $reason)
    {
        $this->jobOrder = $jobOrder;
        $this->payment = $payment;
        $this->reason = $reason;
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
        $shop = $this->jobOrder->shop;
        $shopUrl = $shop?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000') . '/shop/' . $shop->slug) : null;

        $mail = (new MailMessage)
            ->subject('Payment Not Accepted — Order ' . $this->jobOrder->order_number)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your payment of ₱' . number_format((float) $this->payment->amount, 2) . ' for order ' . $this->jobOrder->order_number . ' could not be accepted.')
            ->line('Reason: ' . $this->reason)
            ->line('Please get in touch with the shop or submit a new payment to continue.');

        if ($shopUrl) {
            $mail->action('View Shop', $shopUrl);
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'customer_payment_rejected',
            'title'        => 'Payment Not Accepted',
            'message'      => 'Your ₱' . number_format((float) $this->payment->amount, 2) . ' payment for order ' . $this->jobOrder->order_number . ' was not accepted. Reason: ' . $this->reason,
            'job_order_id' => $this->jobOrder->id,
            'order_number' => $this->jobOrder->order_number,
            'payment_id'   => $this->payment->id,
            'amount'       => (float) $this->payment->amount,
            'reason'       => $this->reason,
        ];
    }
}
