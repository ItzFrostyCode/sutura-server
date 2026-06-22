<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    protected $fillable = [
        'user_id', 'shop_id', 'shop_branch_id', 'role', 'specialization', 
        'bio', 'is_active', 'hired_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'hired_at' => 'date',
        'specialization' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(ShopBranch::class, 'shop_branch_id');
    }
}
