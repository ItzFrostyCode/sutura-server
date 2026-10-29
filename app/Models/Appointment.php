<?php

namespace App\Models;

use App\Notifications\AppointmentStatusNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\DatabaseNotification;

class Appointment extends Model
{
    /** Valid appointment types */
    public const TYPES = ['consultation', 'measurement', 'fitting', 'alteration', 'pickup', 'other'];

    /**
     * Default duration (minutes) per appointment type — the Schedule
     * Appointment form auto-calculates duration from this instead of asking
     * staff to pick one manually. Fitting/Measurement run longer since they
     * involve hands-on work with the customer; Pickup is a quick counter
     * handoff.
     */
    public const TYPE_DEFAULT_DURATIONS = [
        'consultation' => 30,
        'measurement' => 60,
        'fitting' => 60,
        'alteration' => 30,
        'pickup' => 30,
        'other' => 30,
    ];

    /**
     * What a person can book (online) or type in (walk-in). Fitting and pickup are not here on purpose: they
     * only exist as system-made `production` rows tied to a production order.
     */
    public const BOOKABLE_TYPES = ['consultation', 'measurement', 'alteration', 'other'];

    /** What a customer can book online: measuring happens at the consultation, or at a follow-up visit the shop schedules. */
    public const PUBLIC_TYPES = ['consultation', 'alteration', 'other'];

    /** What a general shop visit (no service chosen) can be. Measuring or altering needs a service: there is nothing to measure or alter without one. */
    public const SHOP_VISIT_TYPES = ['consultation', 'other'];

    /** Statuses that use up a day's capacity: a request waiting for an answer, a visit that is on, or one already done. Cancelled, rejected and no-show free it. */
    public const CAPACITY_STATUSES = ['pending', 'confirmed', 'in_progress', 'completed'];

    /** The kinds: service (for a service or design), shop (a general visit), production (system-made). */
    public const KINDS = ['service', 'shop', 'production'];

    /** The kinds a person can create. */
    public const BOOKABLE_KINDS = ['service', 'shop'];

    /** The results of "Done": consultation only, proceed to a production order, the customer didn't come, or the customer did not go ahead with an order ("No order"). */
    public const DONE_RESULTS = ['completed', 'converted_to_job', 'no_show', 'no_order'];

    /** Where the design comes from: the shop's own, the customer's finished file, the customer's to be redone, or none yet. */
    public const DESIGN_SOURCES = ['shop_design', 'customer_ready', 'customer_redo', 'none'];

    /** What a walk-in clash with a waiting online request can be settled with (the owner decides each time). */
    public const ON_CONFLICT = ['serve_walkin_reschedule_online', 'other_time'];

    /** Valid statuses */
    public const STATUSES = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show', 'rejected'];

    /** Why a shop may decline a pending request (code => label shown to the customer). */
    public const REJECTION_REASONS = [
        'schedule_unavailable' => 'Schedule unavailable',
        'staff_unavailable' => 'Staff unavailable',
        'service_unavailable' => 'Service unavailable',
        'capacity_reached' => 'Shop capacity reached',
        'cannot_accommodate' => 'Request cannot be accommodated',
        'other' => 'Other',
    ];

    /**
     * Minimum gap a customer needs between the END of one appointment and
     * the START of their next one, platform-wide — a customer physically
     * cannot teleport from one shop's counter to another's. Applied on top
     * of (not instead of) the plain overlap check in
     * hasCustomerScheduleConflict(): appointment duration and travel buffer
     * are two different things (thesis feedback, 2026-09-28) — two
     * back-to-back appointments with zero gap between them are just as
     * unrealistic as two that literally overlap in time.
     */
    public const CUSTOMER_TRAVEL_BUFFER_MINUTES = 30;

    /**
     * A customer can't book (or move a booking to) a time less than this far
     * ahead — the shop needs notice to prepare. Mirrored by
     * MIN_BOOKING_LEAD_MINUTES in the client's InteractiveCalendar, which
     * greys those slots out. Walk-ins created at the counter are exempt.
     */
    public const MIN_BOOKING_LEAD_MINUTES = 120;

    /** Valid arrival statuses — how the customer's actual arrival compared to scheduled_at. */
    public const ARRIVAL_STATUSES = ['on_time', 'early', 'late', 'no_show'];

    /** Valid early-arrival accommodation decisions. */
    public const EARLY_ARRIVAL_DECISIONS = ['waiting', 'accommodated', 'declined'];

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
        'pending' => ['confirmed', 'cancelled', 'rejected'],
        'confirmed' => ['in_progress', 'cancelled', 'no_show'],
        'in_progress' => ['completed', 'cancelled', 'no_show'],
        'completed' => [],   // terminal — no further transitions
        'cancelled' => [],   // terminal
        'no_show' => [],   // terminal
        'rejected' => [],   // terminal — the shop declined the request
    ];

    protected $fillable = [
        'store_id',
        'store_branch_id',
        'customer_id',
        'service_id',
        'catalog_item_id',
        'selected_size',
        'selected_color',
        'appointment_type',
        'kind',
        'purpose_label',
        'intake_channel',
        'scheduled_at',
        'previous_scheduled_at',
        'visit_notes',
        'design_source',
        'design_link',
        'people_count',
        'pieces_count',
        'duration_minutes',
        'assigned_staff_id',
        'status',
        'notes',
        'reference_images',
        'reference_link',
        'shared_link',
        'shared_images',
        'shared_notes',
        'shared_links',
        'answers',
        'material_source',
        'material_note',
        'job_order_id',
        'payment_method',
        'payment_reference',
        'payment_receipt_path',
        'payment_status',
        'outcome',
        'priority',
        'garment_category',
        'fitting_notes',
        'cancellation_reason',
        'rejection_reason_code',
        'rejection_note',
        'rebooking_blocked',
        // Not yet written by any current controller — this is the
        // read-side/schema half of the Staff Check-In action
        // (STAFF-WORKFLOW.md §1-2), prepared here so the Customer module can
        // read arrival state once that Staff endpoint exists. On-time/Late
        // is derived from this vs scheduled_at, never its own status value.
        'checked_in_at',
        // Early-arrival accommodation workflow (2026-09-28) — see
        // Appointment::ARRIVAL_STATUSES/EARLY_ARRIVAL_DECISIONS. scheduled_at
        // itself is NEVER overwritten by any of this; these are the actual
        // event fields recorded alongside it.
        'arrival_status',
        'early_arrival_decision',
        'actual_service_start_at',
        'accommodated_by_id',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'previous_scheduled_at' => 'datetime',
        'duration_minutes' => 'integer',
        'answers' => 'array',
        'reference_images' => 'array',
        'shared_images' => 'array',
        'shared_links' => 'array',
        'reminder_sent_at' => 'datetime',
        'rebooking_blocked' => 'boolean',
        'checked_in_at' => 'datetime',
        'actual_service_start_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** The catalog design this booking was made from, if any. */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    public function accommodatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accommodated_by_id');
    }

    /** The configurable items (services/designs) included in this appointment request. */
    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    /** Measurement profiles associated with this appointment session. */
    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
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
        return in_array($this->status, ['completed', 'cancelled', 'no_show', 'rejected']);
    }

    /**
     * Compute the end datetime based on duration.
     */
    public function endsAt(): ?Carbon
    {
        if (! $this->scheduled_at) {
            return null;
        }

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
     * A pending production appointment is only a placeholder the system made (the fitting a production order
     * asks for, at a rough time). It must not block an online customer's time, hold a customer's one active
     * booking, or count as a request a walk-in has to bump. Once the shop confirms it, it blocks like any other.
     */
    public static function ignorePendingProduction($query): void
    {
        $query->where(function ($q) {
            $q->where('kind', '!=', 'production')->orWhere('status', '!=', 'pending');
        });
    }

    /** The default kind for a record built without one: fitting/pickup are production, a service link means service. */
    public static function guessKind(array $attributes): string
    {
        if (in_array($attributes['appointment_type'] ?? null, ['fitting', 'pickup'], true)) {
            return 'production';
        }

        return ! empty($attributes['service_id']) || ! empty($attributes['catalog_item_id'])
            ? 'service'
            : 'shop';
    }

    /**
     * The purpose of a booking. A shop visit is what the person picked (consultation, measurement or other).
     * A service visit follows the service: an alteration or repair service means "bring the garment"; any other
     * service is a consultation, or a measurement when asked for.
     */
    public static function purposeFor(string $kind, ?string $requested, ?string $serviceCategory = null): string
    {
        if ($kind === 'shop') {
            return in_array($requested, ['consultation', 'measurement', 'alteration', 'other'], true) ? $requested : 'consultation';
        }

        if ($serviceCategory === 'alterations_repairs') {
            return 'alteration';
        }

        return $requested === 'measurement' ? 'measurement' : 'consultation';
    }

    /** How many bookings already use up the capacity of the day $day at this store (system-made fitting/pickup rows never count). */
    public static function capacityUsedOn(Store $store, Carbon $day, ?int $excludeId = null): int
    {
        return $store->appointments()
            ->whereIn('status', self::CAPACITY_STATUSES)
            ->where('kind', '!=', 'production')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->whereDate('scheduled_at', $day->toDateString())
            ->count();
    }

    /**
     * Visits that happened (or are on) and still have no job order: the next orders to write up.
     * A merely confirmed appointment has not happened yet, so it is not here.
     */
    public function scopeAwaitingJob($query)
    {
        return $query->whereIn('status', ['in_progress', 'completed'])
            ->whereNull('job_order_id')
            ->where('kind', '!=', 'production')
            ->where(fn ($q) => $q->whereNull('outcome')->orWhereNotIn('outcome', ['no_show', 'cancelled', 'rescheduled', 'no_order']));
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
        Store $store,
        ?int $branchId,
        Carbon $scheduledAt,
        int $durationMinutes,
        ?int $excludeId = null,
        bool $includePending = false
    ): bool {
        $newEnd = $scheduledAt->copy()->addMinutes($durationMinutes);

        $statuses = $includePending
            ? ['pending', 'confirmed', 'in_progress']
            : ['confirmed', 'in_progress'];

        $query = $store->appointments()
            ->whereIn('status', $statuses)
            ->where('scheduled_at', '<', $newEnd);
        self::ignorePendingProduction($query);

        if ($branchId !== null) {
            $query->where(function ($q) use ($branchId) {
                $q->where('store_branch_id', $branchId)
                    ->orWhereNull('store_branch_id');
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
     * Cross-shop physical-time-conflict guard — distinct from
     * hasActiveAppointment()'s anti-spam purpose below. Booking two
     * different stores isn't spam, but booking two times too close
     * together across two different stores is a real problem: the customer
     * can't physically be in both places at once, and can't teleport
     * between them either. Platform-wide by design (not scoped to $store),
     * since the whole point is checking the customer's OTHER stores'
     * appointments too. Branch/shop/service never factor in here — a
     * customer's own schedule is what's being protected, not any one
     * store's catalog (thesis feedback, 2026-09-28): booking two DIFFERENT
     * services at two DIFFERENT shops is exactly as blocked by a too-tight
     * gap as booking the same service twice.
     *
     * A straight overlap check isn't enough on its own — 1:30-2:30 PM at
     * Shop A followed immediately by 2:30 PM at Shop B doesn't overlap, but
     * is still physically impossible. CUSTOMER_TRAVEL_BUFFER_MINUTES is
     * added on both sides of the new slot so back-to-back bookings with no
     * travel time between them are blocked when across different locations.
     * When two appointments are at the exact same store branch, travel time
     * between them is zero, so strict time overlap is prevented while allowing
     * multiple legitimate same-day non-overlapping bookings.
     */
    public static function hasCustomerScheduleConflict(
        int $customerId,
        Carbon $scheduledAt,
        int $durationMinutes,
        ?int $excludeId = null,
        ?int $targetStoreId = null,
        ?int $targetBranchId = null
    ): bool {
        $buffer = self::CUSTOMER_TRAVEL_BUFFER_MINUTES;
        $searchEnd = $scheduledAt->copy()->addMinutes($durationMinutes)->addMinutes($buffer);

        $query = self::where('customer_id', $customerId)
            ->whereIn('status', ['pending', 'confirmed', 'in_progress'])
            ->where('scheduled_at', '<', $searchEnd);
        self::ignorePendingProduction($query);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existing = $query->get(['id', 'store_id', 'store_branch_id', 'scheduled_at', 'duration_minutes']);

        $newStart = $scheduledAt->copy();
        $newEnd = $scheduledAt->copy()->addMinutes($durationMinutes);

        foreach ($existing as $appointment) {
            $aptStart = $appointment->scheduled_at->copy();
            $aptEnd = $aptStart->copy()->addMinutes($appointment->duration_minutes ?? 60);

            // Same store location: customer is at the same branch, no cross-town travel needed
            $isSameLocation = ($targetStoreId !== null && (int) $appointment->store_id === (int) $targetStoreId)
                && ($targetBranchId === null || (int) $appointment->store_branch_id === (int) $targetBranchId);

            if ($isSameLocation) {
                // Strict overlap: start < otherEnd && end > otherStart
                if ($newStart->lt($aptEnd) && $newEnd->gt($aptStart)) {
                    return true;
                }
            } else {
                // Different store or branch: enforce physical travel buffer
                $bufferedStart = $aptStart->copy()->subMinutes($buffer);
                $bufferedEnd = $aptEnd->copy()->addMinutes($buffer);
                if ($newStart->lt($bufferedEnd) && $newEnd->gt($bufferedStart)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Anti-spam guard for the public booking form: a customer may only hold
     * one active (pending/confirmed) appointment at a given store at a time.
     * Scoped per-store, not platform-wide — a customer legitimately booking
     * two different stores for two different garments isn't spam. To book a
     * different slot at the *same* store, they cancel the existing one first
     * (PublicBookingController::submit() surfaces this as a 409).
     */
    public static function hasActiveAppointment(Store $store, int $customerId): bool
    {
        // A production fitting/pickup is tied to an order, not a booking the customer made.
        return $store->appointments()
            ->where('customer_id', $customerId)
            ->where('kind', '!=', 'production')
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();
    }

    /**
     * True when this customer's most recent cancelled appointment at this
     * store was cancelled with rebooking explicitly blocked by the store.
     * Only the latest cancellation counts — an older block doesn't linger
     * past a cancellation that didn't renew it.
     */
    public static function isBlockedFromRebooking(Store $store, int $customerId): bool
    {
        $latestCancelled = $store->appointments()
            ->where('customer_id', $customerId)
            ->where('status', 'cancelled')
            ->latest('updated_at')
            ->first(['rebooking_blocked']);

        return (bool) ($latestCancelled?->rebooking_blocked ?? false);
    }

    /**
     * Retrieve all pending appointments that overlap with a given time slot.
     * Used when a walk-in is confirmed to preempt/reschedule conflicting pending online requests.
     */
    public static function getOverlappingPendingAppointments(
        Store $store,
        ?int $branchId,
        Carbon $scheduledAt,
        int $durationMinutes,
        ?int $excludeId = null
    ): Collection {
        $newEnd = $scheduledAt->copy()->addMinutes($durationMinutes);

        // Pending system placeholders (production) are not requests a walk-in has to bump.
        $query = $store->appointments()
            ->where('status', 'pending')
            ->where('kind', '!=', 'production')
            ->where('scheduled_at', '<', $newEnd);

        if ($branchId !== null) {
            $query->where(function ($q) use ($branchId) {
                $q->where('store_branch_id', $branchId)
                    ->orWhereNull('store_branch_id');
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
        // The slot was claimed by a customer at the counter: the request stays pending and says it needs a new time.
        $this->update(['outcome' => 'rescheduled']);

        // Notify the online customer about the walk-in preemption & reschedule prompt
        if ($this->customer) {
            $this->customer->notify(new AppointmentStatusNotification(
                $this,
                'walk_in_preempted',
                "Your requested appointment slot for {$oldScheduledAt} was claimed by an in-store walk-in client who arrived earlier. Please choose an alternative time slot."
            ));
        }

        // Audit log
        $this->store?->auditLogs()->create([
            'user_id' => $actor?->id ?? $this->store->owner_id,
            'action' => 'appointment_preempted_by_walk_in',
            'model_type' => self::class,
            'model_id' => $this->id,
            'payload' => [
                'preempted_appointment_id' => $this->id,
                'walk_in_appointment_id' => $walkInAppointment->id,
                'original_slot' => $oldScheduledAt,
                'reason' => 'Preempted by physical counter walk-in (source of truth)',
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
        // A record built without a kind (old call sites, tests, seeders) gets the one its data implies.
        static::creating(function (self $appointment) {
            if (empty($appointment->getAttributes()['kind'] ?? null)) {
                $appointment->kind = self::guessKind($appointment->getAttributes());
            }
        });

        // A fitting the order made that was cancelled or missed is replaced while the order still waits for it,
        // so a required fitting can never strand the order (the job order never has to be moved back and forth).
        static::updated(function (self $appointment) {
            if (! $appointment->wasChanged('status') || ! in_array($appointment->status, ['cancelled', 'no_show'], true)) {
                return;
            }
            if ($appointment->kind !== 'production' || $appointment->appointment_type !== 'fitting' || ! $appointment->job_order_id) {
                return;
            }
            $job = $appointment->jobOrder;
            if ($job && ! $job->trashed() && $job->status !== 'cancelled' && $job->awaitsFitting() && $appointment->store) {
                $job->createFittingPlaceholder($appointment->store, false);
            }
        });

        static::deleting(function (self $appointment) {
            DatabaseNotification::whereJsonContains('data->appointment_id', $appointment->id)->delete();
        });
    }
}
