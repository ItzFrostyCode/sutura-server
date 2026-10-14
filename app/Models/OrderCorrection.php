<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that has to be fixed or added on an order after production started: a defect found at
 * quality check, an issue found at the fitting, or a change the customer asked for. A change request
 * is quoted and approved before any work is done; a shop-caused error is corrected without charge.
 */
class OrderCorrection extends Model
{
    public const KINDS = ['defect', 'fitting_issue', 'change_request'];

    public const STATUSES = ['open', 'done', 'awaiting_approval', 'cancelled'];

    /** Still holds the order back from being released or completed. */
    public const BLOCKING_STATUSES = ['open', 'awaiting_approval'];

    protected $fillable = [
        'job_order_id', 'kind', 'description', 'affected_quantity', 'assigned_user_id', 'target_date',
        'shop_fault', 'status', 'additional_amount', 'approved_at', 'resolved_at', 'created_by',
    ];

    protected $casts = [
        'affected_quantity' => 'integer',
        'target_date' => 'date:Y-m-d',
        'shop_fault' => 'boolean',
        'additional_amount' => 'float',
        'approved_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isBlocking(): bool
    {
        return in_array($this->status, self::BLOCKING_STATUSES, true);
    }
}
