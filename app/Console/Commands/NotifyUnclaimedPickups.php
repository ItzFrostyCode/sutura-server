<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Shop;
use App\Notifications\UnclaimedPickupsNotification;

#[Signature('app:notify-unclaimed-pickups')]
#[Description('Notify each shop owner once daily if they have job orders sitting ready_for_pickup 14+ days — turns the passive unclaimed_pickups Reports list into a proactive alert.')]
class NotifyUnclaimedPickups extends Command
{
    public function handle(): int
    {
        $notified = 0;

        Shop::where('status', 'approved')->whereNull('deleted_at')->each(function (Shop $shop) use (&$notified) {
            // Same 14-day threshold and query shape as AnalyticsController::index()'s
            // $unclaimedPickups list.
            $unclaimedCount = $shop->jobOrders()
                ->where('status', 'ready_for_pickup')
                ->whereNotNull('ready_for_pickup_at')
                ->where('ready_for_pickup_at', '<=', now()->subDays(14))
                ->count();

            if ($unclaimedCount > 0 && $shop->owner) {
                $shop->owner->notify(new UnclaimedPickupsNotification($shop, $unclaimedCount));
                $notified++;
            }
        });

        $this->info("Notified {$notified} shop owner(s) with unclaimed pickups.");
        return self::SUCCESS;
    }
}
