<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RecentlyViewed extends Model
{
    // "RecentlyViewed" pluralizes to Laravel's guessed "recently_vieweds" —
    // the real table is "recently_viewed" (see the migration).
    protected $table = 'recently_viewed';

    /** Matches the "Showroom / Services / Stores" tab shape used across the
     *  customer-facing Recently Viewed and My Ratings pages. */
    public const TYPES = ['catalog_item', 'service', 'store'];

    private const TYPE_TO_MODEL = [
        'catalog_item' => CatalogItem::class,
        'service' => Service::class,
        'store' => Store::class,
    ];

    protected $fillable = ['user_id', 'viewable_type', 'viewable_id', 'viewed_at'];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function viewable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Record (or bump) a view — one row per user+entity, re-viewing just
     * updates viewed_at rather than creating a duplicate.
     */
    public static function record(int $userId, string $type, int $viewableId): void
    {
        $modelClass = self::TYPE_TO_MODEL[$type] ?? null;
        if (! $modelClass) {
            return;
        }

        self::updateOrCreate(
            ['user_id' => $userId, 'viewable_type' => $modelClass, 'viewable_id' => $viewableId],
            ['viewed_at' => now()]
        );
    }
}
