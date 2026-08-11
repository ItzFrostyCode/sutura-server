<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\Shop;

/**
 * Daily digest for a shop owner whose branch_comparison/overdue_jobs KPI
 * would otherwise sit passively on the dashboard until someone happens to
 * check it — see AnalyticsController::index()'s $overdueJobs query for the
 * exact "overdue" definition this reuses. Fired once per shop per day by
 * app:notify-overdue-jobs, only when the count is actually > 0.
 */
class OverdueJobsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Shop $shop;
    public int $overdueCount;

    public function __construct(Shop $shop, int $overdueCount)
    {
        $this->shop = $shop;
        $this->overdueCount = $overdueCount;
    }

    /**
     * Delivery channels — database only (in-app notification), matching
     * PaymentReceivedNotification/PaymentRejectedNotification's precedent
     * for owner-facing internal alerts (as opposed to customer-facing
     * notifications, which also go out over mail).
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $plural = $this->overdueCount === 1 ? 'job is' : 'jobs are';

        return [
            'type'          => 'overdue_jobs_digest',
            'title'         => 'Overdue Jobs',
            'message'       => "{$this->overdueCount} {$plural} past their due date at {$this->shop->name}.",
            // Straight to the actual overdue jobs, not just a static count
            // the owner would then have to go hunt down themselves — see
            // useJobs' overdueOnly filter.
            'action_url'    => '/dashboard/jobs?overdue=true',
            'shop_id'       => $this->shop->id,
            'overdue_count' => $this->overdueCount,
        ];
    }
}
