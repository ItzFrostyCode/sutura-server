<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop owner's request to move to (or renew) a paid plan, with the GCash
 * proof of payment. Nothing changes on the store until an admin approves it.
 */
class SubscriptionUpgradeRequest extends Model
{
    protected $fillable = [
        'store_id', 'requested_by', 'plan_id', 'billing_cycle', 'quoted_price',
        'payment_method', 'payment_reference', 'payment_receipt_path',
        'status', 'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    // The raw storage path never leaves the server.
    protected $hidden = ['payment_receipt_path'];

    protected $casts = [
        'quoted_price' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
