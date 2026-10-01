<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A place a shop accepts payment (GCash, Maya, a bank account…). SUTURA never moves money: the customer
// pays here outside the app, uploads proof, and the shop verifies it.
class PaymentMethod extends Model
{
    public const KINDS = ['gcash', 'maya', 'bank_transfer', 'other'];

    protected $fillable = [
        'store_id', 'store_branch_id', 'kind', 'name', 'account_name', 'account_number', 'qr_path', 'instructions', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
    }
}
