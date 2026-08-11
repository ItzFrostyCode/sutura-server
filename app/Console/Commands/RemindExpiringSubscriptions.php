<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\ShopSubscription;
use App\Notifications\SubscriptionExpiringNotification;

#[Signature('app:remind-expiring-subscriptions')]
#[Description('Warns shop owners ~3 days before their subscription expires — app:expire-subscriptions only ever notifies after the shop is already hidden, which is too late to act on.')]
class RemindExpiringSubscriptions extends Command
{
    private const WARNING_DAYS = 3;

    public function handle(): int
    {
        // Daily cadence, so no need for the hour-wide window the (also
        // daily-run) appointment reminder uses — a whole day's slop is fine
        // for a multi-day warning, and expiry_reminder_sent_at is what
        // actually prevents a duplicate send either way.
        $windowEnd = now()->addDays(self::WARNING_DAYS)->endOfDay();

        $subscriptions = ShopSubscription::whereIn('status', ['active', 'trial'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', $windowEnd)
            ->whereNull('expiry_reminder_sent_at')
            ->with('shop.owner', 'plan')
            ->get();

        $sent = 0;
        foreach ($subscriptions as $subscription) {
            $owner = $subscription->shop?->owner;
            if (!$owner) {
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
