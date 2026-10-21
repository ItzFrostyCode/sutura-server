<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Measurement extends Model
{
    protected $fillable = [
        'store_id', 'customer_id', 'appointment_id', 'service_id', 'catalog_item_id', 'source', 'profile_name', 'version', 'status', 'metrics', 'notes', 'photo_urls', 'superseded_at',
    ];

    protected $casts = [
        'metrics' => 'array',
        'photo_urls' => 'array',
        'superseded_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** The service this was measured for. */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** The design (of that service) this was measured for, if one was named. */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /** The job orders made with this exact version of the measurements. */
    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class);
    }
}
