<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use SoftDeletes;

    // Small, functional taxonomy driving which conditional Job Order fields
    // apply (see JobOrderController/JobCreateForm) — distinct from the
    // free-text `category`/`tags` fields, which stay as rich marketing labels.
    public const TYPE_CUSTOM_TAILORING = 'custom_tailoring';
    public const TYPE_BULK_SUBLIMATION = 'bulk_sublimation';
    public const TYPE_FASHION_BRIDAL = 'fashion_bridal';
    public const TYPE_ALTERATION_REPAIR = 'alteration_repair';

    public const SERVICE_TYPES = [
        self::TYPE_CUSTOM_TAILORING,
        self::TYPE_BULK_SUBLIMATION,
        self::TYPE_FASHION_BRIDAL,
        self::TYPE_ALTERATION_REPAIR,
    ];

    protected $fillable = [
        'shop_id', 'name', 'description', 'category', 'categories', 'service_type', 'service_types', 'tags',
        'base_price', 'sale_price', 'sale_starts_at', 'sale_ends_at',
        'estimated_days', 'min_order_qty', 'is_active', 'custom_fields', 'image_url',
        'size_chart_image_url', 'size_chart_columns', 'size_chart_rows',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
        'min_order_qty' => 'integer',
        'is_active' => 'boolean',
        'custom_fields' => 'array',
        'tags' => 'array',
        'size_chart_columns' => 'array',
        'size_chart_rows' => 'array',
        'categories' => 'array',
        'service_types' => 'array',
    ];

    /**
     * A service can now carry more than one functional type at once (e.g. a
     * group that's both Bulk/Sublimation and Alterations) — every conditional
     * Job Order rule for each type it has still applies (union, not one-of).
     */
    public function hasType(string $type): bool
    {
        return in_array($type, $this->service_types ?? [], true);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function pricing(): HasMany
    {
        return $this->hasMany(ServicePricing::class, 'service_id');
    }

    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * The price to actually suggest right now, respecting the optional sale
     * window.
     */
    public function effectivePrice(): float
    {
        if ($this->sale_price === null || (float) $this->sale_price >= (float) $this->base_price) {
            return (float) $this->base_price;
        }

        // sale_starts_at/sale_ends_at only ever carry a bare date (no time)
        // from the Set Sale Price form. The app runs on UTC, but the shop is
        // in the Philippines (UTC+8) — comparing the raw 'datetime' cast
        // (which anchors that bare date at UTC midnight) makes a sale
        // advertised as "through Aug 10" actually expire at 8am Manila time
        // on Aug 10, and a sale "starting Aug 5" not kick in until 8am
        // Manila time instead of the start of that day. Re-anchor to the
        // shop's local day boundaries instead.
        $now = now();
        if ($this->sale_starts_at) {
            $startsAt = \Carbon\Carbon::parse($this->sale_starts_at->toDateString(), 'Asia/Manila')->startOfDay();
            if ($now->lt($startsAt)) {
                return (float) $this->base_price;
            }
        }
        if ($this->sale_ends_at) {
            $endsAt = \Carbon\Carbon::parse($this->sale_ends_at->toDateString(), 'Asia/Manila')->endOfDay();
            if ($now->gt($endsAt)) {
                return (float) $this->base_price;
            }
        }

        return (float) $this->sale_price;
    }
}

