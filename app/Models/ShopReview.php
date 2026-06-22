<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopReview extends Model
{
    protected $fillable = [
        'shop_id', 'user_id', 'rating', 'comment', 'reply', 'is_featured'
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_featured' => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
