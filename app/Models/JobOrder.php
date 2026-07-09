<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobOrder extends Model
{
    use SoftDeletes;

    /**
     * Single source of truth for the production lifecycle — unified with the
     * Multi-Stage Staff Assignment stages (design/pattern_making/cutting/
     * sewing/fitting/finishing) so the customer-facing timeline and the
     * internal staff-assignment tracking describe the same process instead
     * of two different vocabularies.
     */
    public const STATUSES = [
        'pending', 'design', 'pattern_making', 'cutting', 'sewing', 'fitting',
        'finishing', 'ready_for_pickup', 'packed', 'handed_to_courier',
        'completed', 'cancelled',
    ];

    /** Assignable Multi-Stage Staff Assignment stages — a subset of STATUSES. */
    public const STAFF_STAGES = ['design', 'pattern_making', 'cutting', 'sewing', 'fitting', 'finishing'];

    /**
     * Who provided the fabric/garment being worked on — the digital equivalent
     * of a shop physically taping a fabric swatch onto a paper logbook entry so
     * staff don't confuse a customer's own material with shop stock. Applies
     * across service types, not just alterations (which already separately
     * track pre-existing damage on an existing garment via custom_order_data).
     */
    public const MATERIAL_SOURCES = ['shop_supplied', 'customer_supplied'];

    protected $fillable = [
        'order_number', 'intake_channel', 'fulfillment_type', 'shop_id', 'shop_branch_id', 'customer_id', 'service_id',
        'catalog_item_id', 'assigned_staff_id', 'measurement_id', 'total_amount',
        'balance', 'payment_status', 'status', 'due_date', 'notes',
        'courier_name', 'courier_tracking_number', 'shipping_address', 'custom_order_data',
        'is_outsourced', 'partner_shop_name', 'outsourcing_cost', 'is_rush', 'rush_fee', 'completion_photo_url',
        'reference_images', 'reference_link', 'material_source',
        'coupon_id', 'discount_amount', 'rejection_reason',
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

}
