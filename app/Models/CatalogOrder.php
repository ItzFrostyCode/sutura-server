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
        'selected_size',
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
        'valid_id_captured',
        'valid_id_notes',
        'return_inspection_notes',
        'deposit_deduction_amount',
        'coupon_id',
        'discount_amount',
    ];

    protected $casts = [
        'valid_id_captured' => 'boolean',
        'deposit_deduction_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        // Explicit Y-m-d format — a bare 'date' cast still round-trips through
        // Eloquent's full datetime format on save, silently writing a
        // "00:00:00" time component into a column the migration deliberately
        // declared as a pure DATE (no time semantics for a rental day range).
        'rental_start_date' => 'date:Y-m-d',
        'rental_end_date' => 'date:Y-m-d',
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
