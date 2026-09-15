<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    /** Valid appointment types */
    public const TYPES = ['consultation', 'measurement', 'fitting', 'alteration', 'pickup'];

    /**
     * Default duration (minutes) per appointment type — the Schedule
     * Appointment form auto-calculates duration from this instead of asking
     * staff to pick one manually. Fitting/Measurement run longer since they
     * involve hands-on work with the customer; Pickup is a quick counter
     * handoff.
     */
    public const TYPE_DEFAULT_DURATIONS = [
        'consultation' => 30,
        'measurement'  => 45,
        'fitting'      => 45,
        'alteration'   => 30,
        'pickup'       => 15,
    ];

    /** Valid statuses */
    public const STATUSES = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];

    /**
     * Types that require a service_id to be set.
     * "Pickup" must also have a linked order — handled at controller level.
     */
    public const TYPES_REQUIRING_SERVICE = ['measurement', 'alteration'];

    /**
     * Valid status transitions.
     * Key = current status, value = allowed next statuses.
     */
    public const TRANSITIONS = [
        'pending'     => ['confirmed', 'cancelled'],
        'confirmed'   => ['in_progress', 'cancelled', 'no_show'],
        'in_progress' => ['completed', 'cancelled'],
        'completed'   => [],   // terminal — no further transitions
        'cancelled'   => [],   // terminal
        'no_show'     => [],   // terminal
    ];

    protected $fillable = [
        'shop_id',
        'shop_branch_id',
        'customer_id',
        'service_id',
        'appointment_type',
        'intake_channel',
        'scheduled_at',
        'duration_minutes',
        'assigned_staff_id',
        'status',
        'notes',
        'reference_images',
        'reference_link',
        'answers',
        'job_order_id',
        'payment_method',
        'payment_reference',
        'payment_receipt_path',
        'payment_status',
        'outcome',
        'priority',
        'garment_category',
        'fitting_notes',
    ];

    protected $casts = [
        'scheduled_at'    => 'datetime',
        'duration_minutes' => 'integer',
        'answers'         => 'array',
        'reference_images' => 'array',
        'reminder_sent_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(ShopBranch::class, 'shop_branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Check if a status transition is valid.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? []);
    }

    /**
     * Check if the appointment is in a terminal (locked) state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'cancelled', 'no_show']);
    }

    /**
     * Compute the end datetime based on duration.
     */
    public function endsAt(): ?\Carbon\Carbon
    {
        if (!$this->scheduled_at) return null;
        return $this->scheduled_at->copy()->addMinutes($this->duration_minutes ?? 60);
    }

    /**
     * Prepare a date for array / JSON serialization without forced UTC 'Z' drift,
     * ensuring frontend date string parsing and JavaScript Date() match local atelier time.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s');
    }

    /**
     * Whether a new [scheduledAt, scheduledAt + duration] window would
     * overlap an existing appointment on the same branch.
     * When $includePending is true (e.g. public storefront customer booking),
     * slots held by pending online requests are also considered conflicting to prevent
     * two online users from requesting the exact same slot simultaneously.
     * When $includePending is false (e.g. in-store walk-in counter intake),
     * only confirmed/in-progress appointments block the slot, because physical
     * walk-in clients are the source of truth ("kung sino ang makauna").
     */
    public static function hasSchedulingConflict(
        Shop $shop,
        ?int $branchId,
        \Carbon\Carbon $scheduledAt,
        int $durationMinutes,
        ?int $excludeId = null,
        bool $includePending = false
    ): bool {
        $newEnd = $scheduledAt->copy()->addMinutes($durationMinutes);

        $statuses = $includePending
            ? ['pending', 'confirmed', 'in_progress']
            : ['confirmed', 'in_progress'];

        $query = $shop->appointments()
            ->whereIn('status', $statuses)
            ->where('scheduled_at', '<', $newEnd);

        if ($branchId !== null) {
            $query->where(function ($q) use ($branchId) {
                $q->where('shop_branch_id', $branchId)
                  ->orWhereNull('shop_branch_id');
            });
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->get(['scheduled_at', 'duration_minutes'])
            ->contains(function (self $appointment) use ($scheduledAt): bool {
                return $appointment->scheduled_at->copy()
                    ->addMinutes($appointment->duration_minutes ?? 60)
                    ->gt($scheduledAt);
            });
    }

    /**
     * Retrieve all pending appointments that overlap with a given time slot.
     * Used when a walk-in is confirmed to preempt/reschedule conflicting pending online requests.
     */
    public static function getOverlappingPendingAppointments(
        Shop $shop,
        ?int $branchId,
        \Carbon\Carbon $scheduledAt,
        int $durationMinutes,
        ?int $excludeId = null
    ): \Illuminate\Database\Eloquent\Collection {
        $newEnd = $scheduledAt->copy()->addMinutes($durationMinutes);

        $query = $shop->appointments()
            ->where('status', 'pending')
            ->where('scheduled_at', '<', $newEnd);

        if ($branchId !== null) {
            $query->where(function ($q) use ($branchId) {
                $q->where('shop_branch_id', $branchId)
                  ->orWhereNull('shop_branch_id');
            });
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->get()
            ->filter(function (self $appointment) use ($scheduledAt): bool {
                return $appointment->scheduled_at->copy()
                    ->addMinutes($appointment->duration_minutes ?? 60)
                    ->gt($scheduledAt);
            })
            ->values();
    }

    /**
     * Preempt this pending appointment when an in-store walk-in claims the time slot.
     * Physical walk-in customers arriving at the atelier are the source of truth ("kung sino ang makauna").
     */
    public function preemptByWalkIn(self $walkInAppointment, ?User $actor = null): void
    {
        $oldScheduledAt = $this->scheduled_at ? $this->scheduled_at->format('M d, Y h:i A') : 'N/A';
        $stamp = "[Walk-in Priority] Time slot claimed by in-person client on " . now()->format('M d, Y h:i A') . ". Reschedule required.";

        $newNotes = $this->notes ? ($stamp . "\n" . $this->notes) : $stamp;

        $this->update([
            'notes'   => $newNotes,
            'outcome' => 'rescheduled',
        ]);

        // Notify the online customer about the walk-in preemption & reschedule prompt
        if ($this->customer) {
            $this->customer->notify(new \App\Notifications\AppointmentStatusNotification(
                $this,
                'walk_in_preempted',
                "Your requested appointment slot for {$oldScheduledAt} was claimed by an in-store walk-in client who arrived earlier. Please choose an alternative time slot."
            ));
        }

        // Audit log
        $this->shop?->auditLogs()->create([
            'user_id'    => $actor?->id ?? $this->shop->owner_id,
            'action'     => 'appointment_preempted_by_walk_in',
            'model_type' => self::class,
            'model_id'   => $this->id,
            'payload'    => [
                'preempted_appointment_id' => $this->id,
                'walk_in_appointment_id'   => $walkInAppointment->id,
                'original_slot'            => $oldScheduledAt,
                'reason'                   => 'Preempted by physical counter walk-in (source of truth)',
            ],
            'ip_address' => request()->ip() ?? '127.0.0.1',
        ]);
    }

    /**
     * Same problem JobOrder::booted() already solves: AppointmentBookedNotification
     * and AppointmentStatusNotification are plain JSON blobs with no foreign
     * key to this table, so deleting an appointment left them behind as dead
     * links that 404 the moment someone clicks through. Confirmed live —
     * "Grace Panganiban booked an appointment..." kept showing in the bell
     * after the appointment itself was gone.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $appointment) {
            \Illuminate\Notifications\DatabaseNotification::whereJsonContains('data->appointment_id', $appointment->id)->delete();
        });
    }
}
