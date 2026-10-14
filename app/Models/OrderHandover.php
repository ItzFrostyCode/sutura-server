<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One release of garments to the customer (a partial release keeps the order open). */
class OrderHandover extends Model
{
    protected $fillable = ['job_order_id', 'quantity', 'received_by', 'note', 'recorded_by', 'handed_over_at'];

    protected $casts = [
        'quantity' => 'integer',
        'handed_over_at' => 'datetime',
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
