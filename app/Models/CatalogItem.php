<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    protected $fillable = [
        'shop_id', 'name', 'price', 'sale_price', 'sale_starts_at', 'sale_ends_at', 'rental_price', 'rental_deposit',
        'material', 'color', 'fabric_image_url', 'sizes', 'description',
        'fit_guide', 'features', 'care_instructions', 'garment_type', 'listing_type', 'external_gallery_url',
        'is_active',
    ];

    protected $casts = [
        'fit_guide' => 'array',
        'features' => 'array',
        'sizes' => 'array',
        'is_active' => 'boolean',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
    ];

    public function images()
    {
        return $this->hasMany(CatalogImage::class);
    }

    public function recommendations()
    {
        return $this->hasMany(CatalogRecommendation::class);
    }

    public function reviews()
    {
        return $this->hasMany(CatalogItemReview::class);
    }

    public function saves()
    {
        return $this->hasMany(CatalogItemSave::class);
    }

    public function catalogOrders()
    {
        return $this->hasMany(CatalogOrder::class, 'catalog_item_id');
    }

    public function jobOrders()
    {
        return $this->hasMany(JobOrder::class, 'catalog_item_id');
    }

    /**
     * The price to actually charge right now — mirrors the frontend's
     * getActiveSale() so what a customer is shown always matches what they're
     * billed. A sale only applies while sale_price is set AND (if given)
     * "now" falls inside the optional start/end window.
     */
    public function effectivePrice(): float
    {
        if ($this->sale_price === null || (float) $this->sale_price >= (float) $this->price) {
            return (float) $this->price;
        }

        $now = now();
        if ($this->sale_starts_at && $now->lt($this->sale_starts_at)) {
            return (float) $this->price;
        }
        if ($this->sale_ends_at && $now->gt($this->sale_ends_at)) {
            return (float) $this->price;
        }

        return (float) $this->sale_price;
    }

    /**
     * A rental item is a single physical unit — two customers reserving
     * overlapping date ranges is a real double-booking, not just a pricing
     * mixup. Only orders still actively holding the item block a new
     * request; a cancelled or already-completed (returned) order doesn't.
     */
    public function hasRentalConflict(string $startDate, string $endDate, ?int $excludeOrderId = null): bool
    {
        $query = $this->catalogOrders()
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where('rental_start_date', '<=', $endDate)
            ->where('rental_end_date', '>=', $startDate);

        if ($excludeOrderId) {
            $query->where('id', '!=', $excludeOrderId);
        }

        return $query->exists();
    }
}
