<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogRecommendation extends Model
{
    protected $fillable = ['catalog_item_id', 'recommended_item_id'];

    public function item()
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    public function recommendedItem()
    {
        return $this->belongsTo(CatalogItem::class, 'recommended_item_id');
    }
}
