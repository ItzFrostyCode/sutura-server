<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CatalogOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'catalog_item_id',
        'customer_id',
        'type',
        'status',
        'total_amount',
        'delivery_address',
        'payment_status',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function catalogItem()
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
