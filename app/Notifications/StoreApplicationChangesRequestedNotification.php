<?php

namespace App\Notifications;

use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Request Changes" on a shop application. Mail only: the applicant can't
 * sign in until approval, so an in-app notification would never be seen.
 * The link is the owner's way in — a private, expiring token for this one
 * application (see StoreApplication::forUpdateToken).
 */
class StoreApplicationChangesRequestedNotification extends Notification
{
    use Queueable;

    /** @param  array<int, array{key: string, label: string}>  $items */
    public function __construct(
        public Store $store,
        public array $items,
        public string $note,
        public string $updateUrl,
        public string $expiresOn,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Action needed on your shop registration — '.$this->store->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We reviewed your registration for '.$this->store->name.' and need a few things updated before we can approve it.')
            ->line('**Please update:**');

        foreach ($this->items as $item) {
            $mail->line('• '.$item['label']);
        }

        return $mail
            ->line('**Note from SUTURA:** '.$this->note)
            ->action('Update My Application', $this->updateUrl)
            ->line('You only need to change the items above; everything else stays as you sent it. This link works until '.$this->expiresOn.'. Please don\'t share it.');
    }
}
