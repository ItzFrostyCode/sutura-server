<?php

namespace App\Models;

use App\Support\OrderReadiness;
use App\Notifications\StoreActivityNotification;
use App\Support\OrderRequirements;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\DatabaseNotification;

class JobOrder extends Model
{
    use SoftDeletes;

    /**
     * Single source of truth for the 3-Phase Tailoring Tracker pipeline —
     * unified with the Multi-Stage Staff Assignment stages (design/
     * pattern_making/cutting/sewing/qc_ironing) so the customer-facing
     * timeline and the internal staff-assignment tracking describe the same
     * process instead of two different vocabularies.
     *
     * - 'mass_cutting_printing' is the Bulk Order Override: a job with a
     *   Team Roster / Size Sheet (custom_order_data.team_roster) skips
     *   'pattern_making' and goes straight here instead.
     * - 'ready_for_fitting' auto-creates a Fitting appointment for the
     *   customer (see JobOrderController@update).
     * - 'final_adjustments' is the revert target if a fitting reveals issues
     *   — from there the job either goes back to 'sewing' for rework or
     *   forward to 'qc_ironing' once resolved.
     */
    public const STATUSES = [
        'pending', 'design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing',
        'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'ready_for_pickup',
        'completed', 'cancelled', 'rejected', 'on_hold',
        // Repair Override short pipeline (isRepairOnly() below) — 'pending',
        // 'ready_for_pickup', 'completed', 'cancelled', 'rejected', 'on_hold'
        // are shared with the custom-tailoring pipeline above; these three are
        // repair-specific. Values only — the actual status-transition logic a
        // repair job moves through is Staff/Owner operational work, not built
        // here (docs/REPAIR-WORKFLOW.md §5, cross-role dependency).
        'queued', 'in_repair', 'qc_check',
        // Ordering flow (Flow 2): an order that has an approved final quotation and a
        // verified deposit is 'confirmed'; it waits in 'awaiting_materials' while any
        // material line is still being bought. Only orders with uses_confirmation_flow
        // pass through these; older orders keep going pending -> production directly.
        'confirmed', 'awaiting_materials',
        // Flow 3: the customer is here to pay the balance and take the garments (see PRODUCTION_TASKS for the rest).
        'balance_handover',
    ];

    /**
     * The internal production tasks. The shop sees each one; the customer just sees "in production".
     * Flow order: these -> qc_ironing (Quality Check) -> ready_for_pickup (Ready for Final Fitting / Collection)
     * -> ready_for_fitting (Customer Fitting / Inspection) -> final_adjustments (Adjustment / Correction)
     * -> balance_handover -> completed. Older orders keep the previous meaning of ready_for_fitting.
     */
    public const PRODUCTION_TASKS = ['design', 'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing'];

    /** Statuses before any actual work has started (the order may still be re-quoted or cancelled freely). */
    public const PRE_PRODUCTION_STATUSES = ['pending', 'confirmed', 'awaiting_materials'];

    /**
     * Assignable Multi-Stage Staff Assignment stages. Deliberately does NOT
     * include 'fitting' — fittings happen front-of-house with the master
     * cutter/owner directly, not a backroom production stage — nor
     * 'mass_cutting_printing', 'final_adjustments', 'ready_for_pickup' etc.,
     * which aren't separately staffed roles.
     */
    public const STAFF_STAGES = ['design', 'pattern_making', 'cutting', 'sewing', 'qc_ironing'];

    /**
     * The "No DP, No Layout, No Cut" golden rule: a job cannot enter any of
     * these production stages until a 50% downpayment has been logged.
     * 'pending' and 'design' are deliberately exempt — no fabric or material
     * is committed yet at those stages, only once pattern-making/cutting
     * actually starts. Enforced in JobOrderController@update; the Kanban
     * board mirrors this same array so the UI and the API never drift apart.
     *
     * 'ready_for_pickup' is included even though it comes after every other
     * gated stage — the status dropdown lets a job jump directly from
     * 'pending'/'design' to ANY stage, not just the next one in sequence, so
     * without this a job with $0 paid could skip straight to "Ready for
     * Pickup" and sit there having never actually passed the DP gate.
     */
    public const STAGES_REQUIRING_DOWNPAYMENT = [
        'pattern_making', 'mass_cutting_printing', 'cutting', 'sewing',
        'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'ready_for_pickup', 'balance_handover',
        // Repair Override pipeline — same "no material committed until a
        // downpayment is logged" rule applies once a repair actually starts
        // (not at 'pending'/'queued', mirroring 'pending'/'design' being
        // exempt above). docs/REPAIR-WORKFLOW.md §5.
        'in_repair', 'qc_check',
    ];

    /**
     * Who provided the fabric/garment being worked on — the digital equivalent
     * of a store physically taping a fabric swatch onto a paper logbook entry so
     * staff don't confuse a customer's own material with store stock. Applies
     * across service types, not just alterations (which already separately
     * track pre-existing damage on an existing garment via custom_order_data).
     */
    public const MATERIAL_SOURCES = ['store_supplied', 'customer_supplied'];

    /**
     * Factual condition of a customer-supplied material, set by the
     * authorized store-side role after physically assessing it — meaningful
     * only when material_source = 'customer_supplied'. The system records
     * the fact only; liability/compensation decisions are never derived from
     * this value. See docs/EMERGENCY-WORKFLOW.md §7.
     */
    public const CUSTOMER_MATERIAL_STATUSES = ['safe', 'damaged', 'lost', 'returned'];

    /**
     * Categorizes why a job order was cancelled. `forfeited_deposit_abandoned`
     * is the one with real financial-reporting consequences (see
     * AnalyticsController) — the customer went uncontactable after fabric was
     * already cut, and per store policy the deposit already collected is kept,
     * not refunded. The other three carry no reversal or reporting logic.
     */
    public const CANCELLATION_REASONS = [
        'customer_request', 'store_unable_to_fulfill', 'forfeited_deposit_abandoned', 'quotation_declined', 'entered_by_mistake', 'other',
    ];

    /**
     * The counts the screens show, worked out here once and sent with every order, so a client never has to recompute them
     * (and can never disagree with handover): the ordered quantity in the unit it was sold in, the physical garments to hand over,
     * and that unit.
     */
    protected $appends = ['ordered_quantity', 'garment_count', 'sold_as'];

    protected $fillable = [
        'order_number', 'tracking_code', 'intake_channel', 'fulfillment_type', 'store_id', 'store_branch_id', 'appointment_id', 'customer_id', 'service_id',
        'catalog_item_id', 'assigned_staff_id', 'measurement_id', 'quantity', 'total_amount',
        'balance', 'payment_status', 'status', 'due_date', 'notes',
        'custom_order_data',
        'measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent',
        'is_rush', 'rush_fee', 'completion_photo_url',
        'reference_images', 'reference_link', 'material_source', 'garment_category',
        'design_source', 'design_link', 'consultation_notes',
        'parent_order_id', 'follow_up_of_id', 'batch_no', 'paid_by_name', 'group_kind',
        'discount_amount', 'rejection_reason', 'cancellation_reason', 'hold_reason', 'status_before_hold',
        'estimated_ready_at', 'customer_material_status',
        // Ordering flow -- server-derived only (no request rule accepts them); the *_at / *_by
        // stamps are written with forceFill() by OrderReadiness / OrderFlowController.
        'uses_confirmation_flow', 'quotation_status', 'quotation', 'shop_accepted_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'total_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        // Explicit Y-m-d — a bare 'date' cast still round-trips through
        // Eloquent's full datetime format on save, silently writing a
        // "00:00:00" time component into a column declared as a pure DATE.
        'due_date' => 'date:Y-m-d',
        // Time-of-day granularity due_date deliberately doesn't have — see
        // docs/REPAIR-WORKFLOW.md §6. A real 'datetime' cast here on purpose,
        // unlike due_date above.
        'estimated_ready_at' => 'datetime',
        'custom_order_data' => 'array',
        'reference_images' => 'array',
        'is_rush' => 'boolean',
        'rush_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'first_adjustment_at' => 'datetime',
        'adjustment_count' => 'integer',
        'progress_photos' => 'array',
        'uses_confirmation_flow' => 'boolean',
        'quotation' => 'array',
        'customer_approval_snapshot' => 'array',
        'specs_approved_at' => 'datetime',
        'measurements_verified_at' => 'datetime',
        'materials_verified_at' => 'datetime',
        'customer_approved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'production_started_at' => 'datetime',
        'qc_passed_at' => 'datetime',
        'fitting_passed_at' => 'datetime',
        'shop_accepted_at' => 'datetime',
        'quotation_sent_at' => 'datetime',
        'quotation_valid_until' => 'date:Y-m-d',
        'customer_decided_at' => 'datetime',
        'payment_terms_locked_at' => 'datetime',
        'released_with_balance_at' => 'datetime',
        'balance_due_by' => 'date',
        'production_authorized_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    /** Every appointment linked to this order: the consultation it was made from (one link) and its fittings and pick-ups. */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** Only the visits this order made itself (fittings and pick-ups), never the consultation it came from. */
    public function fittings(): HasMany
    {
        return $this->hasMany(Appointment::class)->where('kind', 'production');
    }

    /** True while the order sits where the customer is invited to a fitting and still needs one. */
    public function awaitsFitting(): bool
    {
        $flow = OrderReadiness::gated($this);
        $trigger = $flow ? 'ready_for_pickup' : 'ready_for_fitting';

        return $this->status === $trigger && (! $flow || OrderReadiness::fittingNeeded($this));
    }

    /**
     * The placeholder fitting the customer is invited to (tomorrow 10:00, or the next open day, pending: the shop
     * confirms the real time). One open fitting at a time. Past the shop's fitting limit a fee is added to the balance
     * — unless this replaces a fitting that was cancelled or missed, which is not the customer's extra session.
     */
    public function createFittingPlaceholder(Store $store, bool $charge = true): ?Appointment
    {
        if ($this->fittings()->where('appointment_type', 'fitting')->whereIn('status', ['pending', 'confirmed', 'in_progress'])->exists()) {
            return null;
        }

        $prior = $this->fittings()->where('appointment_type', 'fitting')->count();
        $over = $charge && $store->fitting_limit && $prior >= $store->fitting_limit;

        // A rough default the shop moves with the customer; never on a day the shop announced as closed (bounded walk forward).
        $at = now()->addDay()->setTime(10, 0);
        for ($i = 0; $i < 30 && $store->closureTitleOn($at, $this->store_branch_id); $i++) {
            $at = $at->copy()->addDay();
        }

        $appointment = $this->appointments()->create([
            'store_id' => $store->id,
            'store_branch_id' => $this->store_branch_id,
            'customer_id' => $this->customer_id,
            'appointment_type' => 'fitting',
            // Made by the system for this job order: not a walk-in, and a pending one never blocks an online customer's time.
            'kind' => 'production',
            'intake_channel' => 'online',
            'scheduled_at' => $at,
            'duration_minutes' => Appointment::TYPE_DEFAULT_DURATIONS['fitting'],
            'status' => 'pending',
            'notes' => "Made by job order {$this->order_number} — please confirm the actual date and time with the customer."
                .($over ? " This is fitting #{$prior}+1, past the shop's {$store->fitting_limit}-session limit — a ₱{$store->fitting_fee} fitting fee has been added to the balance." : ''),
        ]);

        if ($over && $store->fitting_fee > 0) {
            $this->increment('total_amount', $store->fitting_fee);
            $this->increment('balance', $store->fitting_fee);
            if ($this->payment_status === 'paid') {
                $this->update(['payment_status' => 'partial']);
            }
        }

        return $appointment;
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(OrderCorrection::class)->orderBy('id');
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(OrderHandover::class)->orderBy('id');
    }

    /** Tailors the owner planned for this order (separate from the automatic staffStages attribution). */
    public function assignees(): HasMany
    {
        return $this->hasMany(JobOrderAssignee::class)->orderBy('id');
    }

    public function quotationVersions(): HasMany
    {
        return $this->hasMany(OrderQuotationVersion::class)->orderBy('version');
    }

    public function assigneeEvents(): HasMany
    {
        return $this->hasMany(JobOrderAssigneeEvent::class)->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class)->latest();
    }

    /** True once $status means real work is under way (or finished), as opposed to still being set up. */
    public static function startsProduction(string $status): bool
    {
        return ! in_array($status, [...self::PRE_PRODUCTION_STATUSES, 'cancelled', 'rejected', 'on_hold'], true);
    }

    public function staffStages(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'job_order_staff', 'job_order_id', 'user_id')
            ->using(JobOrderStaff::class)
            ->withPivot('stage', 'assigned_at', 'completed_at')
            ->withTimestamps();
    }

    /**
     * Bulk Order Override signal: a Team Roster / Size Sheet was submitted,
     * or the linked service is itself a bulk-sublimation service. Used to
     * decide whether the job's next production stage after 'design' should
     * be 'mass_cutting_printing' instead of 'pattern_making'.
     */
    public function isBulkOrder(): bool
    {
        if (! empty($this->custom_order_data['team_roster'] ?? null)) {
            return true;
        }

        if (! empty($this->custom_order_data['size_breakdown'] ?? null)) {
            return true;
        }

        return $this->service?->hasType(Service::TYPE_BULK_SUBLIMATION) ?? false;
    }

    /**
     * The task production starts with: Pattern making, Mass cutting for a bulk order, Repair for a repair. Never "design":
     * the design is settled in the request. The ordering flow refuses any other first move out of Confirmed.
     */
    public function firstProductionTask(): string
    {
        if ($this->isRepairOnly()) {
            return 'in_repair';
        }

        return $this->isBulkOrder() ? 'mass_cutting_printing' : 'pattern_making';
    }

    /** The first task in plain words, for messages. */
    public function firstProductionTaskLabel(): string
    {
        return match ($this->firstProductionTask()) {
            'in_repair' => 'Repair',
            'mass_cutting_printing' => 'Mass cutting',
            default => 'Pattern making',
        };
    }

    /**
     * Repair Override signal, mirroring isBulkOrder() above — a job whose
     * garment_category is 'alteration_repair', or whose linked Service
     * carries the alteration_repair type, is a candidate for the short
     * repair pipeline (pending -> queued -> in_repair -> qc_check ->
     * ready_for_pickup -> completed) instead of the full custom-tailoring
     * one. Read-only signal only — this model does not itself drive any
     * automatic status transition; which stage a repair job is actually in
     * remains a Staff/Owner operational decision (docs/REPAIR-WORKFLOW.md §5,
     * docs/STAFF-WORKFLOW.md §8, cross-role dependency).
     */
    /**
     * Whether moving into $status needs the 50% downpayment first. Repairs
     * only need it when the shop opted in (repair_requires_downpayment) —
     * many shops charge repairs at pickup. Everything else always does.
     */
    public function requiresDownpaymentFor(string $status): bool
    {
        if (! in_array($status, self::STAGES_REQUIRING_DOWNPAYMENT, true)) {
            return false;
        }

        if ($this->isRepairOnly() && ! $this->store?->repair_requires_downpayment) {
            return false;
        }

        return $this->requiredDepositFraction() > 0;
    }

    /** Share of the amount due that must be paid before production (snapshotted policy). */
    public function requiredDepositFraction(): float
    {
        return OrderRequirements::depositFraction($this->payment_policy ?? 'deposit', (int) ($this->payment_policy_percent ?? 50));
    }

    /** The team-list size that means "measured for this person" instead of a chart size (same word as the client's CUSTOM_SIZE). */
    public const CUSTOM_SIZE = 'Custom Fit';

    /**
     * Pieces in a team list: each person's own `qty` (at least 1, so a list without quantities is one piece each),
     * added up. A person and a piece are different things: one wearer can need several garments.
     */
    public static function rosterPieces(?array $roster): int
    {
        $pieces = 0;
        foreach ((array) $roster as $row) {
            $pieces += min(500, max(1, (int) (is_array($row) ? ($row['qty'] ?? 1) : 1)));
        }

        return $pieces;
    }

    /** The wearers on a group order (rows of its team list); 0 for an order that is not a group order. */
    public function peopleCount(): int
    {
        return (int) ($this->custom_order_data['people_count'] ?? 0);
    }

    /**
     * Five different numbers, kept apart so none is counted twice:
     *   wearers          peopleCount()       the people (rows of a team list)
     *   per-wearer qty   `qty` on a row      how many units that person asked for
     *   ordered quantity orderedQuantity()   the units sold, in the unit the option was sold in (garments, or sets)
     *   price            amount of the option × ordered quantity: never multiplied by garments per unit
     *   physical garments garmentCount()     what has to be handed over: ordered quantity × garments per unit
     * Minimum quantity and the price share of a split use the ordered quantity; only handover uses garmentCount().
     */

    /** The unit one ordered unit is, taken from the option the order was made from (snapshot); an order with none is sold per garment. */
    public function soldAsSet(): bool
    {
        return ($this->custom_order_data['option_sold_as'] ?? null) === 'set';
    }

    /** Physical garments in ONE ordered unit: a set is one top and one bottom; anything else is one garment. */
    public function garmentsPerUnit(): int
    {
        return $this->soldAsSet() ? 2 : 1;
    }

    /**
     * What was ordered, in the unit it was sold in. A group order made after the people count was recorded has
     * `quantity` 1 because the team list holds the wearers, so its quantity is the units on that list (each
     * person's `qty`, one when none was given). Older orders keep counting `quantity`.
     */
    public function orderedQuantity(): int
    {
        $people = $this->peopleCount();
        if ($people <= 0) {
            return (int) $this->quantity;
        }
        $roster = $this->custom_order_data['team_roster'] ?? null;

        return is_array($roster) && count($roster) > 0 ? self::rosterPieces($roster) : $people;
    }

    /** How many physical garments have to be handed over: the ordered quantity × the garments in one unit (2 for a set). */
    public function garmentCount(): int
    {
        return $this->orderedQuantity() * $this->garmentsPerUnit();
    }

    /** The completed order this one follows up (free fix, repeat or change after pick-up). */
    public function followUpOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'follow_up_of_id');
    }

    /** Orders made as a follow-up to this one. */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'follow_up_of_id');
    }

    /** The first order of a split group; the order itself when it was never split. */
    public function batchRootId(): int
    {
        return (int) ($this->parent_order_id ?? $this->id);
    }

    public function isRepairOnly(): bool
    {
        if ($this->garment_category === 'alteration_repair') {
            return true;
        }

        return $this->service?->hasType(Service::TYPE_ALTERATION_REPAIR) ?? false;
    }

    /**
     * Notifications referencing a job order (NewJobOrderNotification,
     * JobStatusUpdatedNotification, StoreActivityNotification, etc.) are
     * plain JSON blobs with no foreign key to this table — deleting the job
     * used to leave them behind as dead links that 404 the moment someone
     * clicks through. Cleaned up here instead, on both soft and force delete.
     */
    /** True while the order is being split into batches: the new parts do not send a "new order" alert to the staff. */
    public static bool $quietCreate = false;

    /** Set while a trusted customer's order is being created: the rule it had before the downpayment was dropped. */
    public ?array $trustWaiver = null;

    protected static function booted(): void
    {
        // Snapshot the requirements at creation (design -> linked service -> store;
        // store). Every creation path goes through here.
        static::creating(function (JobOrder $job) {
            $store = $job->store_id ? Store::find($job->store_id) : null;
            if (! $store) {
                return;
            }
            $item = $job->catalog_item_id ? CatalogItem::find($job->catalog_item_id) : null;
            $chain = [$item, $item?->service_id ? Service::find($item->service_id) : null];
            if (! $item && $job->service_id) {
                $chain[] = Service::find($job->service_id);
            }
            $chosenPolicy = array_key_exists('payment_policy', $job->getAttributes());
            $resolved = OrderRequirements::resolve($store, $chain);
            foreach ($resolved as $field => $value) {
                if (! array_key_exists($field, $job->getAttributes())) {
                    $job->{$field} = $value;
                }
            }

            // A customer the owner marked as trusted pays at pick-up: no downpayment, unless the caller picked a rule.
            // The old rule goes in the audit log (below) so "require it again" on the order restores it.
            if (! $chosenPolicy && $job->customer_id && $job->payment_policy !== 'none' && $store->isTrustedCustomer((int) $job->customer_id)) {
                $job->trustWaiver = ['previous_policy' => $job->payment_policy, 'previous_percent' => $job->payment_policy_percent];
                $job->payment_policy = 'none';
            }
        });

        // Tell the branch's staff and its manager a new order has landed (their "new order assignment"):
        // everyone at that branch, never the person who made it.
        static::created(function (JobOrder $job) {
            if (self::$quietCreate) {
                return;
            }
            if ($job->trustWaiver) {
                $job->store?->auditLogs()->create([
                    'user_id' => Auth::id(),
                    'action' => 'deposit_waived',
                    'model_type' => self::class,
                    'model_id' => $job->id,
                    'payload' => ['reason' => 'Trusted customer: pays at pick-up'] + $job->trustWaiver,
                ]);
            }

            $staff = User::whereHas('staffProfile', function ($q) use ($job) {
                // A manager hears about an online order through "New online request" instead, so it is not doubled.
                $q->where('store_id', $job->store_id)
                    ->when($job->intake_channel === 'online', fn ($q2) => $q2->where('is_branch_manager', false))
                    ->when($job->store_branch_id, fn ($q2) => $q2->where('store_branch_id', $job->store_branch_id));
            })->where('id', '!=', Auth::id() ?? 0)->get();

            foreach ($staff as $member) {
                $member->notify(new StoreActivityNotification(
                    'job_new_for_branch',
                    'New Order',
                    "Order {$job->order_number} was added".($job->due_date ? ' — due '.\Illuminate\Support\Carbon::parse($job->due_date)->format('M j') : '').'.',
                    '/dashboard/jobs/'.$job->id,
                    ['job_order_id' => $job->id]
                ));
            }
        });

        static::deleting(function (self $jobOrder) {
            DatabaseNotification::whereJsonContains('data->job_order_id', $jobOrder->id)->delete();
        });
    }

    public function getOrderedQuantityAttribute(): int
    {
        return $this->orderedQuantity();
    }

    public function getGarmentCountAttribute(): int
    {
        return $this->garmentCount();
    }

    /** 'set' or 'garment': what one ordered unit is, from the option the order was made from (an order with none is sold per garment). */
    public function getSoldAsAttribute(): string
    {
        return $this->soldAsSet() ? 'set' : 'garment';
    }

    /** The fields of an order that are money: never sent to someone who may not see money (plain staff). */
    public const MONEY_FIELDS = ['total_amount', 'balance', 'discount_amount', 'rush_fee', 'quotation'];

    /** Only the owner and a branch manager see what an order costs and what has been paid. */
    public static function mayRevealMoney(?User $user): bool
    {
        return $user !== null && ($user->hasRole('store_owner') || $user->hasRole('branch_manager'));
    }

    /**
     * What staff may know instead of the amounts: only that something is still owed (`has_balance`), which is what decides whether
     * garments can be handed over. Hidden from the JSON only: the model keeps its values for the server's own rules.
     */
    public function hideMoney(): static
    {
        $this->setAttribute('has_balance', (float) $this->balance > 0.005);
        $this->makeHidden(self::MONEY_FIELDS);
        foreach (['payments', 'quotationVersions', 'refunds'] as $relation) {
            $this->unsetRelation($relation);
        }
        // The price a customer saw when ordering online is kept inside the order's data: not for staff either.
        $data = $this->custom_order_data;
        if (is_array($data) && array_key_exists('item_rate', $data)) {
            unset($data['item_rate']);
            $this->setAttribute('custom_order_data', $data);
            $this->syncOriginalAttribute('custom_order_data');
        }

        return $this;
    }

    /** This order as the viewer may see it: whole for the owner and a branch manager, without the money for anyone else. */
    public function seenBy(?User $viewer): static
    {
        return self::mayRevealMoney($viewer) ? $this : $this->hideMoney();
    }

    /** A message that quotes an amount, said to someone who may not see amounts: the amount is left out. */
    public static function withoutAmounts(string $message, ?User $viewer): string
    {
        if (self::mayRevealMoney($viewer)) {
            return $message;
        }
        // "balance of ₱4,500.00" loses the whole phrase; a bare amount is called "the amount".
        $message = (string) preg_replace('/\s+of\s+₱\s?[\d,]+(?:\.\d+)?/u', '', $message);

        return (string) preg_replace('/₱\s?[\d,]+(?:\.\d+)?/u', 'the amount', $message);
    }
}
