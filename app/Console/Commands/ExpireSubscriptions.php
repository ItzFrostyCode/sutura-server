<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\ShopSubscription;
use App\Notifications\SubscriptionExpiredNotification;

#[Signature('app:expire-subscriptions')]
#[Description('Marks past-due subscriptions as expired and hides the shop from customers until renewed — matches REQUIREMENTS.md Phase 5: "Expired subscriptions automatically downgrade shop visibility to Hidden until renewed."')]
class ExpireSubscriptions extends Command
{
    public function handle(): int
    {
        $expired = 0;

        ShopSubscription::whereIn('status', ['active', 'trial'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->with('shop')
            ->each(function (ShopSubscription $subscription) use (&$expired) {
                $subscription->update(['status' => 'expired']);

                $shop = $subscription->shop;
                if ($shop && !$shop->is_hidden) {
                    $shop->update(['is_hidden' => true]);
                    if ($shop->owner) {
                        $shop->owner->notify(new SubscriptionExpiredNotification($shop));
                    }
                }

                $expired++;
            });

        $this->info("Expired {$expired} subscription(s) and hid the corresponding shop(s).");
        return self::SUCCESS;
    }
}
