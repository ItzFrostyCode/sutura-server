<?php

namespace App\Notifications;

use App\Models\JobOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

// Tells a tailoring staff member that a job order has been assigned to them
class JobOrderAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public JobOrder $jobOrder,
        public ?User $assigner = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $job = $this->jobOrder->loadMissing([
            'customer:id,name',
            'service:id,name',
            'store:id,name',
            'catalogItem:id,name',
        ]);

        $customerName = $job->customer?->name ?? 'Customer';
        $garment = $job->service?->name ?? $job->catalogItem?->name ?? $job->garment_category ?? 'Garment order';
        $orderNo = $job->order_number ?? "#{$job->id}";
        $due = $job->due_date ? ' · Due '.Carbon::parse($job->due_date)->format('M d, Y') : '';
        $storeName = $job->store?->name ?? 'Shop';
        $assignerName = $this->assigner?->name ?? $storeName;

        return [
            'type' => 'job_order_assigned',
            'title' => 'New Order Assigned to You',
            'message' => "Order {$orderNo} ({$garment}) for {$customerName} has been assigned to your production queue{$due}.",
            'action_url' => '/dashboard/jobs',
            'job_order_id' => $job->id,
            'order_number' => $orderNo,
            'customer_name' => $customerName,
            'sender_name' => $assignerName,
            'store_name' => $storeName,
        ];
    }
}
