<?php

namespace App\Notifications;

use App\Models\JobOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The customer's "your payment counted" message — the other side of CustomerPaymentRejectedNotification. Sent when
 * the shop approves a payment the customer sent (GCash / Maya / bank proof), and when the shop records a payment the
 * customer made in person at the counter (cash), so the order's balance never changes without the customer knowing.
 */
class CustomerPaymentConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public JobOrder $jobOrder,
        public float $amount,
        /** True when the shop recorded an in-person payment; false when it approved proof the customer sent. */
        public bool $atCounter,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->email && ! str_starts_with($notifiable->email, 'walkin_')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    private function sentence(): string
    {
        $amount = '₱'.number_format($this->amount, 2);
        $balance = (float) $this->jobOrder->balance;
        $what = $this->atCounter
            ? "The shop recorded your {$amount} payment for order {$this->jobOrder->order_number}."
            : "Your {$amount} payment for order {$this->jobOrder->order_number} was approved.";

        return $what.($balance > 0 ? ' ₱'.number_format($balance, 2).' is left to pay.' : ' The order is fully paid.');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->atCounter ? 'Payment Recorded' : 'Payment Approved').' — Order '.$this->jobOrder->order_number)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->sentence())
            ->action('View Order', rtrim((string) config('app.frontend_url'), '/').'/account/orders/'.$this->jobOrder->id);
    }

    public function toArray(object $notifiable): array
    {
        $store = $this->jobOrder->store;

        return [
            'type' => 'customer_payment_confirmed',
            'title' => $this->atCounter ? 'Payment Recorded' : 'Payment Approved',
            'message' => $this->sentence(),
            'action_url' => '/account/orders/'.$this->jobOrder->id,
            'job_order_id' => $this->jobOrder->id,
            'order_number' => $this->jobOrder->order_number,
            'amount' => $this->amount,
            'balance' => (float) $this->jobOrder->balance,
            'store' => $store ? [
                'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path,
            ] : null,
        ];
    }
}
