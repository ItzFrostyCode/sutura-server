<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    protected $fillable = [
        'shop_id', 'name', 'price', 'estimated_days',
        'material', 'color', 'fabric_image_url', 'sizes', 'description',
        'size_chart_image_url', 'size_chart_columns', 'size_chart_rows',
        'features', 'care_instructions', 'garment_type', 'listing_type', 'external_gallery_url',
        'is_active',
    ];

    protected $casts = [
        'size_chart_columns' => 'array',
        'size_chart_rows' => 'array',
        'features' => 'array',
        'sizes' => 'array',
        'is_active' => 'boolean',
    ];

    // Missing entirely despite shop_id being a real column — every place
    // that needed the owning shop had to join/query around it manually.
    // Needed for CatalogItemReviewReplyNotification's storefront link.
    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

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
