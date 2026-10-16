<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerWelcomeNotification extends Notification
{
    public function __construct(
        public User $user
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        return (new MailMessage)
            ->subject('Welcome to SUTURA — Your Account is Ready')
            ->greeting('Hello '.$this->user->name.',')
            ->line('Thank you for creating an account on SUTURA!')
            ->line('You can now track orders, book tailoring & alteration appointments, and connect directly with verified tailoring shops in Davao City.')
            ->action('Sign In to Your Account', $frontendUrl.'/login?as=customer')
            ->line('If you did not create this account, please ignore this email.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'welcome',
            'title' => 'Welcome to SUTURA',
            'message' => 'Your customer account is ready. Discover shops and track your orders.',
            'action_url' => '/',
        ];
    }
}
