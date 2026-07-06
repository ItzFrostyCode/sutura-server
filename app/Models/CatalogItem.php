<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    protected $fillable = [
        'shop_id', 'name', 'price', 'sale_price', 'rental_price', 'rental_deposit',
        'material', 'color', 'fabric_image_url', 'sizes', 'description',
        'fit_guide', 'features', 'care_instructions', 'garment_type', 'listing_type', 'external_gallery_url',
        'is_active',
    ];

    protected $casts = [
        'fit_guide' => 'array',
        'features' => 'array',
        'sizes' => 'array',
        'is_active' => 'boolean',
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
}
