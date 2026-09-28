<?php

namespace App\Notifications;

use App\Models\JobOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;

    public float $amount;

    public bool $pendingVerification;

    public function __construct(JobOrder $jobOrder, float $amount, bool $pendingVerification = false)
    {
        $this->jobOrder = $jobOrder;
        $this->amount = $amount;
        $this->pendingVerification = $pendingVerification;
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
            'type' => 'payment_received',
            'title' => $this->pendingVerification ? 'Payment Needs Verification' : 'Payment Received',
            'message' => $this->pendingVerification
                ? '₱'.number_format($this->amount, 2).' payment submitted for order '.$this->jobOrder->order_number.' — verify it before it counts toward the balance.'
                : '₱'.number_format($this->amount, 2).' payment received for order '.$this->jobOrder->order_number.'.',
            'action_url' => '/dashboard/jobs/'.$this->jobOrder->id,
            'job_order_id' => $this->jobOrder->id,
            'order_number' => $this->jobOrder->order_number,
            'amount' => $this->amount,
            'pending_verification' => $this->pendingVerification,
            'customer_name' => $this->jobOrder->customer?->name,
        ];
    }
}
