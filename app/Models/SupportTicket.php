<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id', 'user_id', 'subject', 'message', 'attachments',
        'type', 'priority', 'status', 'assigned_to', 'resolved_at', 'catalog_item_id',
    ];

    protected $appends = ['submitted_by_role'];

    protected $casts = [
        'resolved_at' => 'datetime',
        'attachments' => 'array',
    ];

    /** Which kind of account filed it, for the admin's list: owner / branch manager / staff / customer. */
    public function getSubmittedByRoleAttribute(): ?string
    {
        $names = $this->relationLoaded('submittedBy') && $this->submittedBy?->relationLoaded('roles')
            ? $this->submittedBy->roles->pluck('name')->all()
            : [];
        foreach (['store_owner', 'branch_manager', 'staff', 'customer'] as $role) {
            if (in_array($role, $names, true)) {
                return $role;
            }
        }

        return null;
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class, 'ticket_id')->with('user')->orderBy('created_at');
    }
}
