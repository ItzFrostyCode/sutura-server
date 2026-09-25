<?php

namespace App\Notifications;

use App\Models\StoreSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SubscriptionExpiredNotification only ever fires AFTER the store is already
 * hidden — nothing warned the owner beforehand. "Maintain active
 * subscription validity for continued platform visibility" is one of the
 * thesis's own stated specific objectives, so a warning that arrives too
 * late to act on doesn't actually serve it. Mail + database (not
 * database-only like the after-the-fact one) since the whole point is to
 * reach the owner before they'd otherwise notice.
 */
class SubscriptionExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public StoreSubscription $subscription;

    public int $daysRemaining;

    public function __construct(StoreSubscription $subscription, int $daysRemaining)
    {
        $this->subscription = $subscription;
        $this->daysRemaining = $daysRemaining;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $store = $this->subscription->store;
        $planName = $this->subscription->plan?->name ?? 'your plan';
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        return (new MailMessage)
            ->subject('Your Subscription Expires in '.$this->daysRemaining.' Day'.($this->daysRemaining === 1 ? '' : 's'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line($store?->name."'s {$planName} subscription expires in {$this->daysRemaining} day".($this->daysRemaining === 1 ? '' : 's').'.')
            ->line('Once it expires, your store is automatically hidden from customers until you renew.')
            ->action('Renew Now', $frontendUrl.'/dashboard/billing')
            ->line('Renew before then to keep your store visible without interruption.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_expiring',
            'title' => 'Subscription Expiring Soon',
            'message' => 'Your subscription expires in '.$this->daysRemaining.' day'.($this->daysRemaining === 1 ? '' : 's').'. Renew to avoid your store being hidden from customers.',
            'action_url' => '/dashboard/billing',
            'store_id' => $this->subscription->store_id,
            'days_remaining' => $this->daysRemaining,
        ];
    }
}
