<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's saved (hearted) shop. Same shape as ServiceSave and CatalogItemSave. */
class StoreSave extends Model
{
    protected $fillable = ['store_id', 'user_id'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
