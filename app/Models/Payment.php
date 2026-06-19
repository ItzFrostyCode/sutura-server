<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'job_order_id',
        'amount',
        'payment_method',
        'recorded_by',
        'notes'
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
