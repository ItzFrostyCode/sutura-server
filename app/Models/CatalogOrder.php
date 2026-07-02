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
        'payment_method',
        'payment_reference',
        'payment_receipt_path',
        'courier_name',
        'courier_tracking_number',
        'intake_channel',
        'fulfillment_type',
        'rental_start_date',
        'rental_end_date',
        'security_deposit_amount',
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
