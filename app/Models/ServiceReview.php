<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Deliberately no `comment`/`reply` field, unlike StoreReview/CatalogItemReview
// — star-only by design, see the create_service_reviews_table migration.
class ServiceReview extends Model
{
    protected $fillable = [
        'service_id', 'user_id', 'rating',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
