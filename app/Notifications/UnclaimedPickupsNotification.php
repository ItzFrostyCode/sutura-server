<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\Shop;

/**
 * Daily digest for a shop owner with garments still sitting unclaimed on
 * the rack — same "passive KPI into a proactive alert" pattern as
 * OverdueJobsNotification, just for the unclaimed_pickups list added to
 * AnalyticsController::index(). Fired once per shop per day by
 * app:notify-unclaimed-pickups, only when the count is actually > 0.
 */
class UnclaimedPickupsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Shop $shop;
    public int $unclaimedCount;

    public function __construct(Shop $shop, int $unclaimedCount)
    {
        $this->shop = $shop;
        $this->unclaimedCount = $unclaimedCount;
    }

    /** Database only — matches OverdueJobsNotification's owner-facing internal-alert precedent. */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $plural = $this->unclaimedCount === 1 ? 'order has' : 'orders have';

        return [
            'type'             => 'unclaimed_pickups_digest',
            'title'            => 'Unclaimed Pickups',
            'message'          => "{$this->unclaimedCount} {$plural} been ready for pickup 14+ days at {$this->shop->name}.",
            'action_url'       => '/dashboard/reports',
            'shop_id'          => $this->shop->id,
            'unclaimed_count'  => $this->unclaimedCount,
        ];
    }
}
