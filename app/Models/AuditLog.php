<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    /** Most powerful role first — what an actor with several roles is listed as. */
    public const ROLES = ['admin', 'store_owner', 'branch_manager', 'staff', 'customer'];

    /** Request attribute set once any entry is written, so RecordActivity doesn't add a second, vaguer row. */
    public const LOGGED_FLAG = 'activity_logged';

    protected $fillable = [
        'store_id', 'user_id', 'actor_role', 'action', 'model_type',
        'model_id', 'payload', 'ip_address',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        // Every writer (controllers, AdminAudit, RecordActivity) gets the role for free.
        static::creating(function (AuditLog $log) {
            if ($log->actor_role === null) {
                $log->actor_role = $log->user_id ? self::roleOf(User::find($log->user_id)) : 'guest';
            }
        });

        static::created(function () {
            if (app()->bound('request')) {
                request()->attributes->set(self::LOGGED_FLAG, true);
            }
        });
    }

    public static function roleOf(?User $user): string
    {
        if (! $user) {
            return 'guest';
        }
        $roles = $user->roles()->pluck('name')->all();

        return collect(self::ROLES)->first(fn ($r) => in_array($r, $roles, true)) ?? 'customer';
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class); // The actor
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo('model');
    }
}
