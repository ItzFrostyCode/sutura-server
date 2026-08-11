<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Shop;
use App\Notifications\JobsOnHoldNotification;

#[Signature('app:notify-jobs-on-hold')]
#[Description('Notify each shop owner once daily if they have job orders sitting on_hold 7+ days — turns the passive jobs_on_hold Reports list into a proactive alert.')]
class NotifyJobsOnHold extends Command
{
    public function handle(): int
    {
        $notified = 0;

        Shop::where('status', 'approved')->whereNull('deleted_at')->each(function (Shop $shop) use (&$notified) {
            $onHoldCount = $shop->jobOrders()
                ->where('status', 'on_hold')
                ->whereNotNull('held_at')
                ->where('held_at', '<=', now()->subDays(7))
                ->count();

            if ($onHoldCount > 0 && $shop->owner) {
                $shop->owner->notify(new JobsOnHoldNotification($shop, $onHoldCount));
                $notified++;
            }
        });

        $this->info("Notified {$notified} shop owner(s) with jobs on hold.");
        return self::SUCCESS;
    }
}
