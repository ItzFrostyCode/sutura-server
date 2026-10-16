<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * History of who was planned on an order: added, removed, or replaced (with the reason). The current
 * planned tailors stay in `job_order_assignees`; this keeps what changed so a swap never erases the past.
 */
class JobOrderAssigneeEvent extends Model
{
    protected $fillable = ['job_order_id', 'action', 'from_user_id', 'to_user_id', 'reason', 'by_user_id'];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function byUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }
}
