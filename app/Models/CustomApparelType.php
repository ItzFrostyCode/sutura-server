<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A typed-in apparel name (not on the fixed CanonicalTaxonomy list). Designs
 * using it stay off the public storefront until the System Admin approves the
 * name; once approved, every shop typing the same name is listed instantly.
 */
class CustomApparelType extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = ['label', 'normalized', 'status', 'rejection_reason', 'requested_by_store_id', 'reviewed_by', 'reviewed_at'];

    protected $casts = ['reviewed_at' => 'datetime'];

    /** "  Maranao   MALONG " and "maranao malong" are the same name. */
    public static function normalize(string $label): string
    {
        return Str::lower(Str::squish($label));
    }

    /** The existing record for this name, or a new pending one requested by $store. */
    public static function resolveFor(string $label, Store $store): self
    {
        $label = Str::squish($label);

        return self::firstOrCreate(
            ['normalized' => self::normalize($label)],
            ['label' => $label, 'status' => 'pending', 'requested_by_store_id' => $store->id],
        );
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function requestedByStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'requested_by_store_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }
}
