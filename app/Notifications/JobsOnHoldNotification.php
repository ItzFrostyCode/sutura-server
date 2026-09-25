<?php

namespace App\Notifications;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Daily digest for a store owner with job orders sitting on_hold 7+ days —
 * same "passive KPI into a proactive alert" pattern as
 * OverdueJobsNotification/UnclaimedPickupsNotification, for the
 * jobs_on_hold list added to AnalyticsController::index(). Fired once per
 * store per day by app:notify-jobs-on-hold, only when the count is > 0.
 */
class JobsOnHoldNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Store $store;

    public int $onHoldCount;

    public function __construct(Store $store, int $onHoldCount)
    {
        $this->store = $store;
        $this->onHoldCount = $onHoldCount;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $plural = $this->onHoldCount === 1 ? 'order has' : 'orders have';

        return [
            'type' => 'jobs_on_hold_digest',
            'title' => 'Jobs On Hold',
            'message' => "{$this->onHoldCount} {$plural} been on hold 7+ days at {$this->store->name}.",
            'action_url' => '/dashboard/reports',
            'store_id' => $this->store->id,
            'on_hold_count' => $this->onHoldCount,
        ];
    }
}
