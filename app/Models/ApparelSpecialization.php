<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApparelSpecialization extends Model
{
    protected $fillable = [
        'shop_id', 'category', 'name', 'description', 'is_active', 'starting_price', 'production_time_days', 'min_order_qty'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starting_price' => 'float',
        'production_time_days' => 'integer',
        'min_order_qty' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
