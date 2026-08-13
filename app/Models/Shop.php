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
        'logo_path', 'banner_path', 'gallery_images', 'status', 'rejection_reason', 'approved_at', 'approved_by',
        'booking_policy', 'booking_questions', 'max_appointments_per_day', 'latitude', 'longitude', 'social_links',
        'business_type', 'operating_hours',
        'fitting_fee', 'fitting_limit',
        'specializations', 'is_featured', 'is_hidden',
        'gcash_number', 'gcash_account_name', 'bank_name', 'bank_account_number', 'bank_account_name',
        'gcash_qr_path', 'bank_qr_path',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'booking_questions' => 'array',
        'social_links' => 'array',
        'gallery_images' => 'array',
        'operating_hours' => 'array',
        'specializations' => 'array',
        'is_featured' => 'boolean',
        'is_hidden' => 'boolean',
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
        // withPivot('notes') — shop-specific customer notes deliberately live
        // here, not on the User row: a note like "prefers slim fit" only
        // makes sense in the context of the specific shop that wrote it, and
        // suki_tag (which does live on User) already shows the pitfall of a
        // per-customer field that's meant to be shop-specific but isn't.
        return $this->belongsToMany(User::class, 'shop_customers')->withPivot('notes')->withTimestamps();
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
            // This top-of-storefront banner has no branch context to filter
            // by (it renders before a location is picked), so it only ever
            // shows shop-wide announcements — a branch-specific closure
            // shows on that branch's own card instead (see branches()).
            ->whereNull('shop_branch_id')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            // Most-recently-created wins when two ranges overlap (e.g. an
            // urgent closure notice added on top of a standing promo) —
            // otherwise which one displays is undefined DB row order.
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Title of the announced closure covering $date, or null if the shop is
     * open. Shared by AppointmentController (blocking a new booking/
     * reschedule) and JobOrderController (picking a real open day for the
     * auto-generated fitting appointment) instead of duplicating the same
     * query in both — this data previously only ever reached the frontend
     * for display, nothing on the backend ever checked it.
     */
    /**
     * $branchId null matches shop-wide closures only. Passing the branch a
     * booking/appointment actually belongs to also matches a closure
     * announced for just that one branch (shop_branch_id set) — e.g.
     * "Lanang closed for renovation" no longer has to close Main/Matina too.
     */
    public function closureTitleOn(\Carbon\Carbon $date, ?int $branchId = null): ?string
    {
        return $this->specialHours()
            ->where('is_closed', true)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->when(
                $branchId,
                fn ($q) => $q->where(fn ($q2) => $q2->whereNull('shop_branch_id')->orWhere('shop_branch_id', $branchId)),
                fn ($q) => $q->whereNull('shop_branch_id')
            )
            ->value('title');
    }
}
