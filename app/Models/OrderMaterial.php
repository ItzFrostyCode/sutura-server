<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Per-order fabric/trim consumption attribution — NOT an inventory ledger.
// No aggregate stock balance is ever computed from these rows; each row
// only ever describes what was used on one specific job order. See
// staff-module/workroom/09_fabric_remnants_and_retaso_logging.md §4.
class OrderMaterial extends Model
{
    protected $fillable = [
        'job_order_id', 'material_name', 'quantity_used', 'unit',
        'unit_cost', 'subtotal_cost', 'logged_by_staff_id',
    ];

    protected $casts = [
        'quantity_used' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'subtotal_cost' => 'decimal:2',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_staff_id');
    }
}
