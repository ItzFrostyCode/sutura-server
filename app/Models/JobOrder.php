<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number', 'order_type', 'shop_id', 'shop_branch_id', 'customer_id', 'service_id',
        'assigned_staff_id', 'measurement_id', 'total_amount',
        'balance', 'payment_status', 'status', 'due_date', 'notes',
        'courier_name', 'courier_tracking_number', 'shipping_address', 'custom_order_data',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'due_date' => 'date',
        'custom_order_data' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(ShopBranch::class, 'shop_branch_id');
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function staffStages(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'job_order_staff', 'job_order_id', 'user_id')
                    ->using(JobOrderStaff::class)
                    ->withPivot('stage', 'assigned_at', 'completed_at')
                    ->withTimestamps();
    }

    public function inventoryTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }
}
