<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded refund for a cancelled order: what the system suggested, what the
 * owner approved, and why. SUTURA never moves the money (no payment gateway) --
 * `refunded_at` is just when the shop marked it handed back.
 */
class OrderRefund extends Model
{
    public const STAGES = ['no_costs_yet', 'costs_incurred', 'production_started'];

    protected $fillable = [
        'job_order_id', 'stage', 'paid_amount', 'non_refundable_amount', 'non_refundable_note',
        'suggested_amount', 'approved_amount', 'basis', 'adjustment_reason', 'notes', 'recorded_by', 'refunded_at',
    ];

    protected $casts = [
        'paid_amount' => 'float',
        'non_refundable_amount' => 'float',
        'suggested_amount' => 'float',
        'approved_amount' => 'float',
        'basis' => 'array',
        'refunded_at' => 'datetime',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
