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
        'verified_at',
        'verified_by',
        'cash_tendered',
        'change_amount',
    ];

    protected $casts = [
        'rejected_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    protected $appends = ['status'];

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

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** 'rejected' | 'verified' | 'pending_verification' — never stored directly, always derived from the two timestamps so it can't drift out of sync with them. */
    public function getStatusAttribute(): string
    {
        if ($this->rejected_at) {
            return 'rejected';
        }

        return $this->verified_at ? 'verified' : 'pending_verification';
    }
}
