<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    // Single source of truth for valid role values — referenced by both
    // StoreStaffRequest/UpdateStaffRequest (for `role` and `additional_roles.*`)
    // and the frontend's role dropdown options. Includes a few values that
    // only ever showed up in seed data historically (sublimation_specialist,
    // senior_designer, cutter_sewer) so those existing profiles are actually
    // editable instead of showing a blank/unmatched dropdown.
    public const ROLES = [
        'head_tailor', 'tailor', 'cutter', 'seamstress', 'assistant',
        'receptionist', 'quality_control', 'subcontractor', 'designer',
        'pattern_maker', 'sublimation_specialist', 'senior_designer', 'cutter_sewer',
    ];

    protected $fillable = [
        'user_id', 'shop_id', 'shop_branch_id', 'role', 'additional_roles', 'specialization',
        'bio', 'is_active', 'hired_at', 'is_branch_manager'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        // Explicit Y-m-d — a bare 'date' cast still round-trips through
        // Eloquent's full datetime format on save, silently writing a
        // "00:00:00" time component into a column declared as a pure DATE.
        'hired_at' => 'date:Y-m-d',
        'specialization' => 'array',
        'additional_roles' => 'array',
        'is_branch_manager' => 'boolean',
    ];

    protected $appends = ['all_roles'];

    /** Primary role first (rank 1), then additional roles in rank order. */
    public function getAllRolesAttribute(): array
    {
        return array_values(array_filter(array_merge([$this->role], $this->additional_roles ?? [])));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(ShopBranch::class, 'shop_branch_id');
    }
}
