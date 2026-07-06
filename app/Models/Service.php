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
        'shop_id', 'name', 'description', 'category', 'service_type', 'tags',
        'base_price', 'estimated_days', 'min_order_qty', 'is_active', 'custom_fields', 'image_url',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'min_order_qty' => 'integer',
        'is_active' => 'boolean',
        'custom_fields' => 'array',
        'tags' => 'array',
    ];

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
}

