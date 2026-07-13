<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\JobOrder;

/**
 * The missing link in the Customer → Shop Owner → Staff → Customer chain —
 * every other notification in the app reaches either the customer or the
 * shop owner, but nothing ever told a staff member they'd been assigned to a
 * production stage. Fires from JobOrderController@store (new job, staffed at
 * creation) and @assignStaff (reassigned later), only for stages that are
 * actually new or handed to a different person — not on a no-op re-save.
 */
class StaffAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;
    public string $stage;

    public function __construct(JobOrder $jobOrder, string $stage)
    {
        $this->jobOrder = $jobOrder;
        $this->stage = $stage;
    }

    /**
     * Delivery channels — database only (in-app notification). This is an
     * internal staffing assignment, not a customer-facing update, so no
     * email is sent.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    private function stageLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->stage));
    }

    public function toArray(object $notifiable): array
    {
        $stageLabel = $this->stageLabel();

        return [
            'type'          => 'staff_assigned',
            'title'         => "Assigned to {$stageLabel}",
            'message'       => "You've been assigned to the {$stageLabel} stage on job order {$this->jobOrder->order_number}"
                . ($this->jobOrder->customer?->name ? " for {$this->jobOrder->customer->name}." : '.'),
            'action_url'    => '/dashboard/jobs/' . $this->jobOrder->id,
            'job_order_id'  => $this->jobOrder->id,
            'order_number'  => $this->jobOrder->order_number,
            'stage'         => $this->stage,
        ];
    }
}
