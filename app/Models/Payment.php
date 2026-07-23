<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'job_order_id',
        'amount',
        'payment_method',
        'reference',
        'recorded_by',
        'notes',
        'receipt_path',
        'rejected_at',
        'rejected_reason',
        'rejected_by',
    ];

    protected $casts = [
        'rejected_at' => 'datetime',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
