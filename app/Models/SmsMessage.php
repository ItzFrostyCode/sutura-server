<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    public const STATUSES = ['draft', 'approved', 'sent', 'failed', 'blocked', 'cancelled'];

    protected $fillable = [
        'store_id', 'user_id', 'event', 'related_type', 'related_id', 'dedupe_key', 'to_number', 'raw_number', 'body', 'segments',
        'status', 'blocked_reason', 'is_test', 'provider', 'provider_message_id', 'error', 'approved_by', 'approved_at', 'sent_at',
    ];

    protected $casts = ['is_test' => 'boolean', 'approved_at' => 'datetime', 'sent_at' => 'datetime'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
