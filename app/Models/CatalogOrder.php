<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatalogOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'store_branch_id',
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

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
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
