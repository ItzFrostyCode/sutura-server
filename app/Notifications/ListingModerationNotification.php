<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a shop owner about an admin moderation action on their shop or one
 * of its catalog designs (Admin\ModerationController). Mail + database —
 * rare and consequential, same reasoning as StoreApplicationStatusNotification.
 */
class ListingModerationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $action  'warned' | 'hidden' | 'restored'
     * @param  string  $target  'store' | 'catalog_item'
     */
    public function __construct(
        public string $action,
        public string $target,
        public string $name,
        public ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function title(): string
    {
        return match ($this->action) {
            'warned' => 'Action Needed on Your Listing',
            'hidden' => $this->target === 'store' ? 'Your Shop Has Been Hidden' : 'A Catalog Design Was Hidden',
            default => $this->target === 'store' ? 'Your Shop Is Visible Again' : 'Your Catalog Design Is Visible Again',
        };
    }

    private function message(): string
    {
        $subject = $this->target === 'store' ? 'Your shop "'.$this->name.'"' : 'Your catalog design "'.$this->name.'"';
        $reason = $this->reason ? ' Reason: '.$this->reason : '';

        return match ($this->action) {
            'warned' => $subject.' was reported and needs a change (for example, replacing an image). It stays visible for now — please update it.'.$reason,
            'hidden' => $subject.' has been hidden from customers by the SUTURA administrator.'.$reason.' You can still edit it; an administrator will review it before it goes live again.',
            default => $subject.' has been reviewed and is visible to customers again.',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->message())
            ->action('Go to Your Dashboard', env('FRONTEND_URL', 'http://localhost:3000').'/dashboard');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'listing_'.$this->action,
            'title' => $this->title(),
            'message' => $this->message(),
            'action_url' => $this->target === 'store' ? '/dashboard/settings' : '/dashboard/catalog',
        ];
    }
}
