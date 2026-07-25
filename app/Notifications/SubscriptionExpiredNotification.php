<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\Shop;

/**
 * Fired once by app:expire-subscriptions when a shop's subscription lapses
 * and the shop is auto-hidden as a result — matches
 * OverdueJobsNotification's "database only, owner-facing" precedent.
 */
class SubscriptionExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Shop $shop;

    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'subscription_expired',
            'title'      => 'Subscription Expired',
            'message'    => "{$this->shop->name}'s subscription has expired and your shop is now hidden from customers. Renew to restore visibility.",
            'action_url' => '/dashboard/billing',
            'shop_id'    => $this->shop->id,
        ];
    }
}
