<?php

namespace App\Support;

use App\Models\CatalogItem;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Store;
use Illuminate\Validation\Rule;

/**
 * Measurement / final-fitting / payment requirements.
 *
 * Precedence (most specific wins, per field): design -> its linked service ->
 * store default. A combo package uses its own setting, else the store default.
 * Job orders snapshot the resolved values when they are created.
 */
class OrderRequirements
{
    public const MEASUREMENT = ['none', 'existing', 'shop'];

    public const FITTING = ['none', 'optional', 'required'];

    public const PAYMENT = ['none', 'full', 'deposit', 'custom'];

    /** Validation rules for the nullable offering-level fields (service/design/combo). */
    public static function offeringRules(): array
    {
        return [
            'measurement_requirement' => ['nullable', Rule::in(self::MEASUREMENT)],
            'fitting_requirement' => ['nullable', Rule::in(self::FITTING)],
            'payment_policy' => ['nullable', Rule::in(self::PAYMENT)],
            'payment_policy_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** Validation rules for the store-wide defaults (never null). */
    public static function storeRules(): array
    {
        return [
            'default_measurement_requirement' => ['sometimes', Rule::in(self::MEASUREMENT)],
            'default_fitting_requirement' => ['sometimes', Rule::in(self::FITTING)],
            'default_payment_policy' => ['sometimes', Rule::in(self::PAYMENT)],
            'default_payment_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @param  array<int, CatalogItem|ServicePackage|Service|null>  $chain  most specific first
     * @return array{measurement_requirement:string,fitting_requirement:string,payment_policy:string,payment_policy_percent:int}
     */
    public static function resolve(Store $store, array $chain): array
    {
        $pick = function (string $field, string $storeValue) use ($chain) {
            foreach ($chain as $source) {
                if ($source && $source->{$field} !== null) {
                    return $source->{$field};
                }
            }

            return $storeValue;
        };

        $policy = $pick('payment_policy', $store->default_payment_policy ?? 'deposit');
        // The percent travels with whichever level supplied the policy.
        $percent = $store->default_payment_percent ?? 50;
        foreach ($chain as $source) {
            if ($source && $source->payment_policy !== null) {
                $percent = $source->payment_policy_percent ?? 50;
                break;
            }
        }

        return [
            'measurement_requirement' => $pick('measurement_requirement', $store->default_measurement_requirement ?? 'shop'),
            'fitting_requirement' => $pick('fitting_requirement', $store->default_fitting_requirement ?? 'optional'),
            'payment_policy' => $policy,
            'payment_policy_percent' => (int) $percent,
        ];
    }

    /** Share of the amount due that must be paid before production. */
    public static function depositFraction(string $policy, int $percent): float
    {
        return match ($policy) {
            'none' => 0.0,
            'full' => 1.0,
            default => max(1, min(100, $percent)) / 100,
        };
    }
}
