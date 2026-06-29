<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    protected $fillable = [
        'shop_id', 'user_id', 'action', 'model_type',
        'model_id', 'payload', 'ip_address'
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class); // The actor
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo('model');
    }
}
