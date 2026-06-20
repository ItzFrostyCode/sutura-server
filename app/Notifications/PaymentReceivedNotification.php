<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\JobOrder;

class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;
    public float $amount;

    public function __construct(JobOrder $jobOrder, float $amount)
    {
        $this->jobOrder = $jobOrder;
        $this->amount   = $amount;
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
        return [
            'type'          => 'payment_received',
            'title'         => 'Payment Received',
            'message'       => '₱' . number_format($this->amount, 2) . ' payment received for order ' . $this->jobOrder->order_number . '.',
            'action_url'    => '/dashboard/jobs/' . $this->jobOrder->id,
            'job_order_id'  => $this->jobOrder->id,
            'order_number'  => $this->jobOrder->order_number,
            'amount'        => $this->amount,
            'customer_name' => $this->jobOrder->customer?->name,
        ];
    }
}
