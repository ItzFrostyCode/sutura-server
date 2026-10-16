<?php

namespace App\Services\Sms;

use App\Models\Appointment;
use App\Models\JobOrder;
use App\Support\SmsText;
use Carbon\Carbon;

/**
 * The few texts worth paying for. Each is written to fit ONE sms (160 plain-ASCII characters): the shop name and
 * any free text are shortened first, never the message itself. Only moments a customer must act on get a text —
 * confirmations, changes, reminders, "ready" — not every production stage.
 */
class SmsTemplates
{
    public const EVENTS = [
        'appointment_confirmed' => 'Appointment confirmed',
        'appointment_rejected' => 'Appointment declined',
        'appointment_rescheduled' => 'Appointment moved',
        'appointment_cancelled' => 'Appointment cancelled',
        'appointment_reminder' => 'Appointment reminder',
        'order_ready_fitting' => 'Ready for fitting',
        'order_ready_pickup' => 'Ready for pickup',
        'order_quotation_ready' => 'Price (quotation) is ready',
    ];

    /**
     * Texts cost money and customers already get an email and an in-app notice for everything, so by default only
     * the moments where a missed message costs the shop a visit or a no-show are texted. A shop can switch any on or off.
     */
    public const DEFAULT_ENABLED = ['appointment_confirmed', 'appointment_rescheduled', 'appointment_cancelled', 'appointment_reminder', 'order_ready_fitting', 'order_ready_pickup'];

    /** @return array<int,string> */
    public static function enabledFor(?array $stored): array
    {
        return array_values(array_intersect(array_keys(self::EVENTS), $stored ?? self::DEFAULT_ENABLED));
    }

    private const KINDS = ['consultation' => 'consultation', 'measurement' => 'measurement', 'fitting' => 'fitting', 'alteration' => 'alteration', 'pickup' => 'pick-up'];

    private static function when(Appointment $a): string
    {
        return $a->scheduled_at ? Carbon::parse($a->scheduled_at)->format('D M j, g:iA') : 'the set time';
    }

    private static function kind(Appointment $a): string
    {
        return $a->appointment_type === 'other' && $a->purpose_label ? SmsText::fit($a->purpose_label, 18) : (self::KINDS[$a->appointment_type] ?? 'appointment');
    }

    /** @return array{event:string, body:string, dedupe:string}|null */
    public static function appointment(Appointment $a, string $status): ?array
    {
        $shop = SmsText::shopName($a->store?->name);
        $kind = self::kind($a);
        $when = self::when($a);
        $key = 'appointment:'.$a->id;

        return match ($status) {
            'confirmed' => ['event' => 'appointment_confirmed', 'body' => "{$shop}: your {$kind} on {$when} is CONFIRMED. See you!", 'dedupe' => "{$key}:confirmed:{$when}"],
            'rejected' => ['event' => 'appointment_rejected', 'body' => "{$shop}: sorry, we can't take your {$kind} on {$when}. Please pick another time in SUTURA.", 'dedupe' => "{$key}:rejected"],
            'rescheduled' => ['event' => 'appointment_rescheduled', 'body' => "{$shop}: your {$kind} is moved to {$when}. Open SUTURA to confirm or pick another time.", 'dedupe' => "{$key}:rescheduled:{$when}"],
            'cancelled' => ['event' => 'appointment_cancelled', 'body' => "{$shop}: your {$kind} on {$when} was cancelled. Book a new time anytime in SUTURA.", 'dedupe' => "{$key}:cancelled"],
            default => null,
        };
    }

    /** @return array{event:string, body:string, dedupe:string} */
    public static function reminder(Appointment $a): array
    {
        $shop = SmsText::shopName($a->store?->name);

        return [
            'event' => 'appointment_reminder',
            'body' => "{$shop} reminder: your ".self::kind($a).' is tomorrow, '.self::when($a).'. Reply not needed.',
            'dedupe' => 'appointment:'.$a->id.':reminder:'.Carbon::parse($a->scheduled_at)->toDateString(),
        ];
    }

    /** What the customer should bring when they come in: the balance, the full amount, or nothing. */
    private static function payLine(JobOrder $job): string
    {
        $balance = (float) $job->balance;
        if ($balance <= 0.005) {
            return ' Fully paid.';
        }
        $paid = \App\Support\OrderReadiness::paidSoFar($job);

        return $paid > 0.005
            ? ' Bring the balance: PHP '.number_format($balance, 2).'.'
            : ' Bring full payment: PHP '.number_format($balance, 2).'.';
    }

    /** "Your price is ready" — only sent when the shop switches this event on (it is not in DEFAULT_ENABLED). */
    public static function quotation(JobOrder $job): array
    {
        $shop = SmsText::shopName($job->store?->name);
        $no = SmsText::fit($job->order_number, 12);

        return [
            'event' => 'order_quotation_ready',
            'body' => "{$shop}: the price for order {$no} is ready: PHP ".number_format((float) $job->total_amount, 2).'. Open SUTURA to review and approve. Code: '.$job->tracking_code,
            'dedupe' => 'job:'.$job->id.':quote:'.(int) $job->quotationVersions()->max('version'),
        ];
    }

    /** @return array{event:string, body:string, dedupe:string}|null */
    public static function order(JobOrder $job, string $stage): ?array
    {
        $shop = SmsText::shopName($job->store?->name);
        $no = SmsText::fit($job->order_number, 12);

        if ($stage === 'ready_for_fitting') {
            return ['event' => 'order_ready_fitting', 'body' => "{$shop}: order {$no} is ready for FITTING. We will confirm your time. Track code: {$job->tracking_code}", 'dedupe' => 'job:'.$job->id.':fitting:'.now()->toDateString()];
        }
        if ($stage === 'ready_for_pickup') {
            $pay = self::payLine($job);
            if (\App\Support\OrderReadiness::gated($job)) {
                $what = \App\Support\OrderReadiness::fittingNeeded($job) ? 'FITTING' : 'PICK-UP';

                return ['event' => 'order_ready_pickup', 'body' => "{$shop}: order {$no} is ready. Please come for {$what}.{$pay} Track code: {$job->tracking_code}", 'dedupe' => 'job:'.$job->id.':pickup:'.now()->toDateString()];
            }

            return ['event' => 'order_ready_pickup', 'body' => "{$shop}: order {$no} is ready for PICK-UP.{$pay} Track code: {$job->tracking_code}", 'dedupe' => 'job:'.$job->id.':pickup'];
        }

        return null;
    }
}
