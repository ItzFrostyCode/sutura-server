<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\User;

class PaymentRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;
    public Payment $payment;
    public User $rejectedBy;

    public function __construct(JobOrder $jobOrder, Payment $payment, User $rejectedBy)
    {
        $this->jobOrder   = $jobOrder;
        $this->payment    = $payment;
        $this->rejectedBy = $rejectedBy;
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
            'type'         => 'payment_rejected',
            'title'        => 'Payment Rejected',
            'message'      => '₱' . number_format((float) $this->payment->amount, 2) . ' payment on order ' . $this->jobOrder->order_number . ' was rejected by ' . $this->rejectedBy->name . '.',
            'action_url'   => '/dashboard/jobs/' . $this->jobOrder->id,
            'job_order_id' => $this->jobOrder->id,
            'order_number' => $this->jobOrder->order_number,
            'payment_id'   => $this->payment->id,
            'amount'       => (float) $this->payment->amount,
            'rejected_by'  => $this->rejectedBy->name,
        ];
    }
}
