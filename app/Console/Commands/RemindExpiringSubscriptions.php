<?php

namespace App\Console\Commands;

use App\Models\StoreSubscription;
use App\Notifications\SubscriptionExpiringNotification;
use Illuminate\Console\Command;

class RemindExpiringSubscriptions extends Command
{
    protected $signature = 'app:remind-expiring-subscriptions';

    protected $description = 'Warns store owners ~3 days before their subscription expires — app:expire-subscriptions only ever notifies after the store is already hidden, which is too late to act on.';

    private const WARNING_DAYS = 3;

    public function handle(): int
    {
        // Daily cadence, so no need for the hour-wide window the (also
        // daily-run) appointment reminder uses — a whole day's slop is fine
        // for a multi-day warning, and expiry_reminder_sent_at is what
        // actually prevents a duplicate send either way.
        $windowEnd = now()->addDays(self::WARNING_DAYS)->endOfDay();

        $subscriptions = StoreSubscription::whereIn('status', ['active', 'trial'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', $windowEnd)
            ->whereNull('expiry_reminder_sent_at')
            ->with('store.owner', 'plan')
            ->get();

        $sent = 0;
        foreach ($subscriptions as $subscription) {
            $owner = $subscription->store?->owner;
            if (! $owner) {
                continue;
            }
            $daysRemaining = max(1, (int) now()->diffInDays($subscription->ends_at, false) + 1);
            $owner->notify(new SubscriptionExpiringNotification($subscription, $daysRemaining));
            $subscription->forceFill(['expiry_reminder_sent_at' => now()])->save();
            $sent++;
        }

        $this->info("Sent {$sent} subscription-expiring reminder(s).");

        return self::SUCCESS;
    }
}
