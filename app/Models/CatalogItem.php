<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    protected $fillable = [
        'shop_id', 'name', 'price', 'material', 'description', 
        'fit_guide', 'features', 'care_instructions', 'garment_type', 'listing_type', 'external_gallery_url'
    ];

    protected $casts = [
        'fit_guide' => 'array',
        'features' => 'array',
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
}
