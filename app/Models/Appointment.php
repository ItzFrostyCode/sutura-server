<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    /** Valid appointment types */
    public const TYPES = ['consultation', 'measurement', 'fitting', 'alteration', 'pickup'];

    /** Valid statuses */
    public const STATUSES = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];

    /**
     * Types that require a service_id to be set.
     * "Pickup" must also have a linked order — handled at controller level.
     */
    public const TYPES_REQUIRING_SERVICE = ['measurement', 'fitting', 'alteration'];

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
        'scheduled_at',
        'duration_minutes',
        'assigned_staff_id',
        'status',
        'notes',
        'answers',
    ];

    protected $casts = [
        'scheduled_at'    => 'datetime',
        'duration_minutes' => 'integer',
        'answers'         => 'array',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
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
}
