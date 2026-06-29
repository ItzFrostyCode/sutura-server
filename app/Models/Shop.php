<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_id', 'name', 'slug', 'description', 'address', 'landmark',
        'city', 'province', 'postal_code', 'phone', 'email', 
        'logo_path', 'status', 'rejection_reason', 'approved_at', 'approved_by',
        'currency', 'booking_policy', 'booking_questions', 'latitude', 'longitude', 'social_links', 'gallery_images',
        'business_type', 'operating_hours',
        'security_deposit', 'rental_duration_days', 'overdue_penalty_per_day', 'fitting_fee', 'fitting_limit',
        'reschedule_fee_percent', 'change_reserved_hours', 'change_reserved_fee_percent', 'supported_couriers'
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'booking_questions' => 'array',
        'social_links' => 'array',
        'gallery_images' => 'array',
        'operating_hours' => 'array',
        'supported_couriers' => 'array',
        'security_deposit' => 'float',
        'overdue_penalty_per_day' => 'float',
        'fitting_fee' => 'float',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ShopSubscription::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(StaffProfile::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function apparelSpecializations(): HasMany
    {
        return $this->hasMany(ApparelSpecialization::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(ShopBranch::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(Measurement::class);
    }

    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ShopSubscription::class)->latestOfMany();
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }

    public function customers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shop_customers');
    }

    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ShopReview::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
