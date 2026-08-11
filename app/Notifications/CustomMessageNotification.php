<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\JobOrder;

/**
 * Free-form message the owner types and sends directly to a job order's
 * customer — separate from the automatic status-change notifications
 * (JobStatusUpdatedNotification etc.), which fire on their own on every
 * pipeline transition. This one only fires when the owner explicitly
 * chooses to send it (see JobOrderController@notifyCustomer), for things
 * the automatic templates don't cover — a reminder, an ad hoc heads-up,
 * anything the owner wants to say in their own words.
 */
class CustomMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public JobOrder $jobOrder;
    public string $subject;
    public string $body;

    public function __construct(JobOrder $jobOrder, string $subject, string $body)
    {
        $this->jobOrder = $jobOrder;
        $this->subject = $subject;
        $this->body = $body;
    }

    /**
     * Mail only — this notification exists specifically because the owner
     * wants to reach the customer's inbox, so no in-app bell copy (the
     * owner already knows they sent it; the customer isn't a dashboard
     * user who'd see a bell).
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $shop = $this->jobOrder->shop;
        $shopUrl = $shop?->slug ? url(env('FRONTEND_URL', 'http://localhost:3000') . '/shop/' . $shop->slug) : null;

        $mail = (new MailMessage)
            ->subject($this->subject . ' — ' . ($shop?->name ?? 'SUTURA'))
            ->greeting('Hello ' . $notifiable->name . ',');

        // Preserve the owner's paragraph breaks as separate lines rather than
        // collapsing free-form text into one dense block.
        foreach (preg_split('/\r?\n/', trim($this->body)) as $paragraph) {
            if ($paragraph !== '') {
                $mail->line($paragraph);
            }
        }

        if ($shopUrl) {
            $mail->action('Visit ' . $shop->name, $shopUrl);
        }

        return $mail;
    }
}
