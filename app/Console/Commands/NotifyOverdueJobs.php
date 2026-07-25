<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Shop;
use App\Notifications\OverdueJobsNotification;

#[Signature('app:notify-overdue-jobs')]
#[Description('Notify each shop owner once daily if they have job orders past their due_date — turns the passive overdue_jobs KPI into a proactive alert.')]
class NotifyOverdueJobs extends Command
{
    public function handle(): int
    {
        $today = now()->toDateString();
        $notified = 0;

        Shop::where('status', 'approved')->whereNull('deleted_at')->each(function (Shop $shop) use ($today, &$notified) {
            // Same "overdue" definition as AnalyticsController::index()'s
            // $overdueJobs query — on_hold/rejected are deliberately excluded
            // alongside completed/cancelled.
            $overdueCount = $shop->jobOrders()
                ->whereNotIn('status', ['completed', 'cancelled', 'on_hold', 'rejected'])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today)
                ->count();

            if ($overdueCount > 0 && $shop->owner) {
                $shop->owner->notify(new OverdueJobsNotification($shop, $overdueCount));
                $notified++;
            }
        });

        $this->info("Notified {$notified} shop owner(s) with overdue jobs.");
        return self::SUCCESS;
    }
}
