<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved copy of an order's quotation. A change to the price, date or payment terms adds a new
 * version; older versions are never overwritten, so the shop can show what was offered and when.
 */
class OrderQuotationVersion extends Model
{
    protected $fillable = [
        'job_order_id', 'version', 'status', 'total', 'due_date', 'valid_until', 'payment_policy',
        'payment_policy_percent', 'snapshot', 'reason', 'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'total' => 'float',
        'due_date' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
        'payment_policy_percent' => 'integer',
        'snapshot' => 'array',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
