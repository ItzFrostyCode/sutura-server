<?php

namespace App\Support;

use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Send a notification without letting a delivery failure (bad SMTP login, provider down) undo or
 * fail the action that triggered it. The failure is logged; the order change has already happened.
 */
final class SafeNotify
{
    public static function send(?object $notifiable, Notification $notification): void
    {
        if (! $notifiable) {
            return;
        }
        try {
            $notifiable->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
