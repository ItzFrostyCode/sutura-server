<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\SupportTicket;

/**
 * Admin::SupportTicketAdminController@reply had no notification of any
 * kind — the shop owner who filed the ticket would only find out someone
 * replied by manually reopening /dashboard/support. Since the admin
 * frontend doesn't exist yet, admin replies are the shop owner's only
 * signal their issue is being worked on at all.
 */
class SupportTicketReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public SupportTicket $ticket;
    public string $replierName;

    public function __construct(SupportTicket $ticket, string $replierName)
    {
        $this->ticket = $ticket;
        $this->replierName = $replierName;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        return (new MailMessage)
            ->subject('New Reply on Your Support Ticket — ' . $this->ticket->subject)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($this->replierName . ' replied to your support ticket "' . $this->ticket->subject . '".')
            ->action('View Ticket', $frontendUrl . '/dashboard/support')
            ->line('Reply there to continue the conversation.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support_ticket_reply',
            'title' => 'New Reply on Your Support Ticket',
            'message' => $this->replierName . ' replied to "' . $this->ticket->subject . '".',
            'action_url' => '/dashboard/support',
            'ticket_id' => $this->ticket->id,
        ];
    }
}
