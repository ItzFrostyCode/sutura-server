<?php

namespace App\Notifications;

use App\Models\Store;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your shop is approved — here's your login." Sent on approval instead of
 * StoreApplicationStatusNotification's approved mail.
 *
 * Deliberately NOT ShouldQueue: it carries a plaintext temporary password,
 * and a queued notification is serialized into the jobs table if the queue
 * is ever moved off `sync`. The in-app (database) copy never includes the
 * password. Mail is routed to the owner's contact_email by
 * User::routeNotificationForMail(), since the shop login itself has no inbox.
 */
class ShopLoginIssuedNotification extends Notification
{
    public function __construct(
        public Store $store,
        private string $loginEmail,
        private string $temporaryPassword,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        return (new MailMessage)
            ->subject('Your Shop Is Approved — Here\'s Your SUTURA Login')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->store->name.' has been approved and is now live on SUTURA.')
            ->line('Sign in on the **Shop** tab with:')
            ->line('**Login:** '.$this->loginEmail)
            ->line('**Temporary password:** '.$this->temporaryPassword)
            ->line('You\'ll be asked to choose your own password the first time you sign in.')
            ->action('Sign In to Your Shop', $frontendUrl.'/login?as=store')
            ->line('This login is only for your shop. Keep using your personal email for anything you buy or book as a customer.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'store_approved',
            'title' => 'Shop Approved',
            'message' => $this->store->name.' has been approved. You now have full access to your dashboard.',
            'action_url' => '/dashboard',
            'store_id' => $this->store->id,
        ];
    }
}
