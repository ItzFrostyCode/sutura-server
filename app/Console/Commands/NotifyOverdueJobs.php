<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Notifications\OverdueJobsNotification;
use Illuminate\Console\Command;

class NotifyOverdueJobs extends Command
{
    protected $signature = 'app:notify-overdue-jobs';

    protected $description = 'Notify each store owner once daily if they have job orders past their due_date — turns the passive overdue_jobs KPI into a proactive alert.';

    public function handle(): int
    {
        $today = now()->toDateString();
        $notified = 0;

        Store::where('status', 'approved')->whereNull('deleted_at')->each(function (Store $store) use ($today, &$notified) {
            // Same "overdue" definition as AnalyticsController::index()'s
            // $overdueJobs query — on_hold/rejected are deliberately excluded
            // alongside completed/cancelled.
            $overdueCount = $store->jobOrders()
                ->whereNotIn('status', ['completed', 'cancelled', 'on_hold', 'rejected'])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today)
                ->count();

            if ($overdueCount > 0 && $store->owner) {
                $store->owner->notify(new OverdueJobsNotification($store, $overdueCount));
                $notified++;
            }
        });

        $this->info("Notified {$notified} store owner(s) with overdue jobs.");

        return self::SUCCESS;
    }
}
