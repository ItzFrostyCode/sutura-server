<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the address typed on the shop-owner sign-up page, before any account
 * exists (so it goes to an on-demand route, not a User). Sent immediately, not
 * queued: the owner is looking at the "Check your inbox" screen.
 */
class ConfirmOwnerEmailNotification extends Notification
{
    public function __construct(private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email to create your SUTURA account')
            ->greeting('Confirm your email')
            ->line('Thanks for signing up for SUTURA! Press the button to confirm your email. Your account is created when you do.')
            ->action('Confirm my email', $this->url)
            ->line('The button works for 24 hours. If you did not sign up, ignore this email and no account will be created.');
    }
}
