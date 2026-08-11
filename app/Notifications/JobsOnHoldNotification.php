<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\Shop;

/**
 * Daily digest for a shop owner with job orders sitting on_hold 7+ days —
 * same "passive KPI into a proactive alert" pattern as
 * OverdueJobsNotification/UnclaimedPickupsNotification, for the
 * jobs_on_hold list added to AnalyticsController::index(). Fired once per
 * shop per day by app:notify-jobs-on-hold, only when the count is > 0.
 */
class JobsOnHoldNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Shop $shop;
    public int $onHoldCount;

    public function __construct(Shop $shop, int $onHoldCount)
    {
        $this->shop = $shop;
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
            'type'           => 'jobs_on_hold_digest',
            'title'          => 'Jobs On Hold',
            'message'        => "{$this->onHoldCount} {$plural} been on hold 7+ days at {$this->shop->name}.",
            'action_url'     => '/dashboard/reports',
            'shop_id'        => $this->shop->id,
            'on_hold_count'  => $this->onHoldCount,
        ];
    }
}
