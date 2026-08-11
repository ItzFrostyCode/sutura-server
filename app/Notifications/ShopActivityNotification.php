<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Generic owner-facing "something moved" notification — job status
 * transitions and appointment status changes previously only notified the
 * customer (JobStatusUpdatedNotification / AppointmentStatusNotification),
 * so a shop owner had no in-app visibility when staff or a branch manager
 * changed something on their behalf; the only events that reached the
 * owner's own notification bell were "new job created" and "payment
 * received". Database-only by design — this is an internal activity feed,
 * not a customer-facing email.
 */
class ShopActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $notifType,
        private readonly string $title,
        private readonly string $message,
        private readonly string $actionUrl,
        private readonly array $extra = []
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_merge([
            'type'       => $this->notifType,
            'title'      => $this->title,
            'message'    => $this->message,
            'action_url' => $this->actionUrl,
        ], $this->extra);
    }
}
