<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class JobOrderStaff extends Pivot
{
    protected $table = 'job_order_staff';

    protected $fillable = [
        'job_order_id',
        'user_id',
        'stage',
        'assigned_at',
        'completed_at'
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime'
    ];
}
