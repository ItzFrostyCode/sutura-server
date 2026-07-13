<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopSpecialHour extends Model
{
    protected $fillable = [
        'shop_id',
        'title',
        'start_date',
        'end_date',
        'is_closed',
        'special_open_time',
        'special_close_time',
        'announcement_message',
        'announcement_image_url',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'is_closed' => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
