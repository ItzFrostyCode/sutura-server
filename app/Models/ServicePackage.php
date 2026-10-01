<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePackage extends Model
{
    protected $fillable = [
        'store_id', 'name', 'description', 'service_category', 'image_url', 'bundle_price', 'is_active',
        'measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent',
    ];

    protected $casts = [
        'bundle_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_package_items', 'service_package_id', 'service_id');
    }

    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ServicePackageReview::class);
    }
}
