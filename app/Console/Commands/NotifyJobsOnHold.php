<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Notifications\JobsOnHoldNotification;
use Illuminate\Console\Command;

class NotifyJobsOnHold extends Command
{
    protected $signature = 'app:notify-jobs-on-hold';

    protected $description = 'Notify each store owner once daily if they have job orders sitting on_hold 7+ days — turns the passive jobs_on_hold Reports list into a proactive alert.';

    public function handle(): int
    {
        $notified = 0;

        Store::where('status', 'approved')->whereNull('deleted_at')->each(function (Store $store) use (&$notified) {
            $onHoldCount = $store->jobOrders()
                ->where('status', 'on_hold')
                ->whereNotNull('held_at')
                ->where('held_at', '<=', now()->subDays(7))
                ->count();

            if ($onHoldCount > 0 && $store->owner) {
                $store->owner->notify(new JobsOnHoldNotification($store, $onHoldCount));
                $notified++;
            }
        });

        $this->info("Notified {$notified} store owner(s) with jobs on hold.");

        return self::SUCCESS;
    }
}
