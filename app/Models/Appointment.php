<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    protected $fillable = [
        'shop_id', 'shop_branch_id', 'customer_id', 'service_id', 'scheduled_at', 'status', 'notes', 'answers'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'answers' => 'array'
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
        return $this->belongsTo(Service::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(ShopBranch::class, 'shop_branch_id');
    }
}
