<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CatalogOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'shop_branch_id',
        'catalog_item_id',
        'selected_size',
        'customer_id',
        'type',
        'status',
        'total_amount',
        'payment_status',
        'payment_method',
        'payment_reference',
        'payment_receipt_path',
        'intake_channel',
        'fulfillment_type',
        'discount_amount',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function branch()
    {
        return $this->belongsTo(ShopBranch::class, 'shop_branch_id');
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
