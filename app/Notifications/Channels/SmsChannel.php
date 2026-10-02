<?php

namespace App\Notifications\Channels;

use App\Models\Store;
use App\Services\Sms\SmsOutbox;
use Illuminate\Notifications\Notification;

/**
 * Lets any notification add a text by listing this channel in via() and implementing toSms(). The text does not go
 * out from here: it lands in the shop's SMS outbox (SmsOutbox), which decides whether it waits for review.
 */
class SmsChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $payload = method_exists($notification, 'toSms') ? $notification->toSms($notifiable) : null;
        if (! $payload || ! ($store = Store::find($payload['store_id']))) {
            return;
        }
        SmsOutbox::queue($store, $notifiable, $payload['event'], $payload['body'], $payload['related_type'] ?? null, $payload['related_id'] ?? null, $payload['dedupe'] ?? null);
    }
}
