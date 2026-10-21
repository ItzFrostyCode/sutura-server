<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentItem extends Model
{
    protected $fillable = [
        'appointment_id',
        'service_id',
        'catalog_item_id',
        'item_name',
        'quantity',
        'unit_price',
        'selected_size',
        'selected_color',
        'add_ons',
        'specifications',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'add_ons' => 'array',
        'specifications' => 'array',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
