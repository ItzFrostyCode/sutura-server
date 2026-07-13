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
        'booking_policy', 'booking_questions', 'max_appointments_per_day', 'latitude', 'longitude', 'social_links',
        'business_type', 'operating_hours',
        'security_deposit', 'rental_duration_days', 'overdue_penalty_per_day', 'fitting_fee', 'fitting_limit',
        'reschedule_fee_percent', 'change_reserved_hours', 'change_reserved_fee_percent', 'supported_couriers'
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'booking_questions' => 'array',
        'social_links' => 'array',
        'operating_hours' => 'array',
        'supported_couriers' => 'array',
        'security_deposit' => 'float',
        'overdue_penalty_per_day' => 'float',
        'fitting_fee' => 'float',
        'max_appointments_per_day' => 'integer',
    ];

    protected $appends = [
        'active_special_hours',
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

    public function servicePackages(): HasMany
    {
        return $this->hasMany(ServicePackage::class);
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

    public function posts(): HasMany
    {
        return $this->hasMany(ShopPost::class);
    }

    public function specialHours(): HasMany
    {
        return $this->hasMany(ShopSpecialHour::class);
    }

    public function getActiveSpecialHoursAttribute()
    {
        // Asia/Manila, not the app's UTC default — the app-wide timezone is
        // UTC, so comparing against a bare now()->toDateString() could be up
        // to 8 hours off from the shop's actual local day boundary.
        $today = now('Asia/Manila')->toDateString();
        return $this->specialHours()
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            // Most-recently-created wins when two ranges overlap (e.g. an
            // urgent closure notice added on top of a standing promo) —
            // otherwise which one displays is undefined DB row order.
            ->orderByDesc('created_at')
            ->first();
    }
}
