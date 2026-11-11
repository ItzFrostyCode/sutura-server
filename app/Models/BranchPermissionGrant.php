<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BranchPermissionGrant extends Model
{
    protected $fillable = ['store_id', 'store_branch_id', 'user_id', 'permission', 'granted_by', 'revoked_by', 'revoked_at'];

    protected $casts = ['revoked_at' => 'datetime'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at');
    }
}
