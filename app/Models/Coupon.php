<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coupon extends Model
{
    protected $fillable = [
        'shop_id', 'code', 'discount_type', 'discount_value', 'applies_to',
        'usage_limit', 'used_count', 'starts_at', 'ends_at', 'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Whether this code can currently be redeemed for the given module
     * ("catalog" or "services") — checks active flag, the optional date
     * window (blank on either side = no limit), the usage cap, and scope.
     */
    public function isValidFor(string $context): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->applies_to !== 'all' && $this->applies_to !== $context) {
            return false;
        }

        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    public function computeDiscount(float $amount): float
    {
        $discount = $this->discount_type === 'percent'
            ? $amount * (min((float) $this->discount_value, 100) / 100)
            : (float) $this->discount_value;

        return round(min($discount, $amount), 2);
    }

    /**
     * Atomically re-validates and increments usage under a row lock, so two
     * near-simultaneous redemptions of a usage-limited code can't both pass
     * isValidFor()'s check before either increments and exceed the limit —
     * the same class of race pay() already guards against for balances.
     * Returns the locked, incremented coupon, or null if it's no longer valid
     * (e.g. someone else just used the last redemption).
     */
    public static function redeem(int $couponId, string $context): ?self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($couponId, $context) {
            $coupon = self::where('id', $couponId)->lockForUpdate()->first();
            if (!$coupon || !$coupon->isValidFor($context)) {
                return null;
            }
            $coupon->increment('used_count');
            return $coupon;
        });
    }
}
