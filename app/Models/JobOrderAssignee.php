<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tailor the owner PLANNED for an order. Separate from `job_order_staff`,
 * which stays the automatic "who actually worked this stage" attribution that
 * the productivity report reads -- planning never writes to it.
 */
class JobOrderAssignee extends Model
{
    public const ROLES = ['lead', 'tailor', 'assistant'];

    protected $fillable = ['job_order_id', 'user_id', 'role', 'note', 'assigned_by'];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
