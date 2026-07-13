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
    ];

    protected $casts = [
        'scheduled_at'    => 'datetime',
        'duration_minutes' => 'integer',
        'answers'         => 'array',
        'reference_images' => 'array',
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
     * Whether a new [scheduledAt, scheduledAt + duration] window would
     * overlap an existing confirmed appointment on the same branch. Shared
     * by the public booking form and the owner's manual create/reschedule
     * flow so double-booking is blocked consistently everywhere, not just
     * for customers booking online.
     */
    public static function hasSchedulingConflict(
        Shop $shop,
        ?int $branchId,
        \Carbon\Carbon $scheduledAt,
        int $durationMinutes,
        ?int $excludeId = null
    ): bool {
        $newEnd = $scheduledAt->copy()->addMinutes($durationMinutes);

        $query = $shop->appointments()
            ->where('shop_branch_id', $branchId)
            ->where('status', 'confirmed')
            ->where('scheduled_at', '<', $newEnd);

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
}
