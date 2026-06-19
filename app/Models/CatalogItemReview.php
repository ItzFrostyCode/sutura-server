<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogItemReview extends Model
{
    protected $fillable = [
        'catalog_item_id', 'user_id', 'rating', 'comment'
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
