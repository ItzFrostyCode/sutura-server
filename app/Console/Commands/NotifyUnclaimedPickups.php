<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Notifications\UnclaimedPickupsNotification;
use Illuminate\Console\Command;

class NotifyUnclaimedPickups extends Command
{
    protected $signature = 'app:notify-unclaimed-pickups';

    protected $description = 'Notify each store owner once daily if they have job orders sitting ready_for_pickup 14+ days — turns the passive unclaimed_pickups Reports list into a proactive alert.';

    public function handle(): int
    {
        $notified = 0;

        Store::where('status', 'approved')->whereNull('deleted_at')->each(function (Store $store) use (&$notified) {
            // Same 14-day threshold and query shape as AnalyticsController::index()'s
            // $unclaimedPickups list.
            $unclaimedCount = $store->jobOrders()
                ->where('status', 'ready_for_pickup')
                ->whereNotNull('ready_for_pickup_at')
                ->where('ready_for_pickup_at', '<=', now()->subDays(14))
                ->count();

            if ($unclaimedCount > 0 && $store->owner) {
                $store->owner->notify(new UnclaimedPickupsNotification($store, $unclaimedCount));
                $notified++;
            }
        });

        $this->info("Notified {$notified} store owner(s) with unclaimed pickups.");

        return self::SUCCESS;
    }
}
