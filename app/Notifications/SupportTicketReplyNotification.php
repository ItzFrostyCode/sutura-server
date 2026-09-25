<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin::SupportTicketAdminController@reply had no notification of any
 * kind — the store owner who filed the ticket would only find out someone
 * replied by manually reopening /dashboard/support. Since the admin
 * frontend doesn't exist yet, admin replies are the store owner's only
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
            ->subject('New Reply on Your Support Ticket — '.$this->ticket->subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->replierName.' replied to your support ticket "'.$this->ticket->subject.'".')
            ->action('View Ticket', $frontendUrl.'/dashboard/support')
            ->line('Reply there to continue the conversation.');
    }

    public function toArray(object $notifiable): array
    {
        // submittedBy isn't always the shop owner — a customer can also file
        // a ticket (e.g. "Report This Product" on a catalog item), and their
        // reply notification must land on their own /account thread, not the
        // owner-only dashboard route they have no access to.
        $isCustomer = $notifiable->roles?->contains('name', 'customer') ?? false;
        $store = $this->ticket->store;

        return [
            'type' => 'support_ticket_reply',
            'title' => 'New Reply on Your Support Ticket',
            'message' => $this->replierName.' replied to "'.$this->ticket->subject.'".',
            'action_url' => $isCustomer
                ? '/account/settings/support/'.$this->ticket->id
                : '/dashboard/support',
            'ticket_id' => $this->ticket->id,
            'store' => $store ? [
                'id' => $store->id, 'name' => $store->name, 'slug' => $store->slug, 'logo_path' => $store->logo_path,
            ] : null,
        ];
    }
}
