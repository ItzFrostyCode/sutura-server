<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogImage extends Model
{
    protected $fillable = ['catalog_item_id', 'image_url', 'view_angle', 'is_primary'];

    public function item()
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }
}
