<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

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
        'ready_for_fitting', 'final_adjustments', 'qc_ironing', 'ready_for_pickup',
    ];

    /**
     * Who provided the fabric/garment being worked on — the digital equivalent
     * of a shop physically taping a fabric swatch onto a paper logbook entry so
     * staff don't confuse a customer's own material with shop stock. Applies
     * across service types, not just alterations (which already separately
     * track pre-existing damage on an existing garment via custom_order_data).
     */
    public const MATERIAL_SOURCES = ['shop_supplied', 'customer_supplied'];

    /**
     * Categorizes why a job order was cancelled. `forfeited_deposit_abandoned`
     * is the one with real financial-reporting consequences (see
     * AnalyticsController) — the customer went uncontactable after fabric was
     * already cut, and per shop policy the deposit already collected is kept,
     * not refunded. The other three carry no reversal or reporting logic.
     */
    public const CANCELLATION_REASONS = [
        'customer_request', 'shop_unable_to_fulfill', 'forfeited_deposit_abandoned', 'other',
    ];

    protected $fillable = [
        'order_number', 'tracking_code', 'intake_channel', 'fulfillment_type', 'shop_id', 'shop_branch_id', 'customer_id', 'service_id',
        'catalog_item_id', 'assigned_staff_id', 'measurement_id', 'total_amount',
        'balance', 'payment_status', 'status', 'due_date', 'notes',
        'custom_order_data',
        'is_outsourced', 'partner_shop_name', 'outsourcing_cost', 'is_rush', 'rush_fee', 'completion_photo_url',
        'reference_images', 'reference_link', 'material_source', 'garment_category',
        'discount_amount', 'rejection_reason', 'cancellation_reason', 'hold_reason',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        // Explicit Y-m-d — a bare 'date' cast still round-trips through
        // Eloquent's full datetime format on save, silently writing a
        // "00:00:00" time component into a column declared as a pure DATE.
        'due_date' => 'date:Y-m-d',
        'custom_order_data' => 'array',
        'reference_images' => 'array',
        'is_outsourced' => 'boolean',
        'is_rush' => 'boolean',
        'rush_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'outsourcing_cost' => 'decimal:2',
        'first_adjustment_at' => 'datetime',
        'adjustment_count' => 'integer',
        'progress_photos' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
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

    public function appointments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Appointment::class);
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
        return $this->belongsTo(ShopBranch::class, 'shop_branch_id');
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function staffStages(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
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
        if (!empty($this->custom_order_data['team_roster'] ?? null)) {
            return true;
        }

        return $this->service?->hasType(Service::TYPE_BULK_SUBLIMATION) ?? false;
    }

    /**
     * Notifications referencing a job order (NewJobOrderNotification,
     * JobStatusUpdatedNotification, ShopActivityNotification, etc.) are
     * plain JSON blobs with no foreign key to this table — deleting the job
     * used to leave them behind as dead links that 404 the moment someone
     * clicks through. Cleaned up here instead, on both soft and force delete.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $jobOrder) {
            \Illuminate\Notifications\DatabaseNotification::whereJsonContains('data->job_order_id', $jobOrder->id)->delete();
        });
    }
}
