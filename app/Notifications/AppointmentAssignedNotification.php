<?php

namespace App\Notifications;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

// Tells a staff member an appointment is theirs to handle (appointment assignment only —
// production-stage attribution on job orders is a separate, automatic mechanism).
class AppointmentAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(public Appointment $appointment) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $a = $this->appointment->loadMissing(['customer:id,name', 'service:id,name', 'catalogItem:id,name', 'servicePackage:id,name', 'branch:id,name']);
        $what = $a->catalogItem?->name ?? $a->servicePackage?->name ?? $a->service?->name;
        $when = $a->scheduled_at ? Carbon::parse($a->scheduled_at)->format('M d, Y h:i A') : 'a scheduled time';
        $type = str_replace('_', ' ', (string) $a->appointment_type);

        return [
            'type' => 'appointment_assigned',
            'title' => 'New appointment assigned to you',
            'message' => trim(($a->customer?->name ?? 'A customer').' — '.$type.($what ? ' · '.$what : '').' · '.$when.($a->branch ? ' · '.$a->branch->name : '')),
            'action_url' => '/dashboard/appointments',
            'appointment_id' => $a->id,
            'scheduled_at' => $a->scheduled_at,
        ];
    }
}
