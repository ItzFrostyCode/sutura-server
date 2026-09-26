<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePackageReview extends Model
{
    protected $fillable = [
        'service_package_id', 'user_id', 'rating',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}