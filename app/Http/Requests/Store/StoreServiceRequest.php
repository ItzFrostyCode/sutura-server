<?php

namespace App\Http\Requests\Store;

use App\Models\Service;
use App\Support\CanonicalTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Services are shop-wide, so only the shop owner writes them. A branch manager can read the list
        // but not change it. The route group (role:store_owner) is the first gate; this is the second.
        return $this->user()->hasRole('store_owner');
    }

    public function rules(): array
    {
        $serviceParam = $this->route('service');
        $serviceModel = $serviceParam instanceof Service ? $serviceParam : (is_numeric($serviceParam) ? Service::find($serviceParam) : null);
        $group = (string) ($this->input('service_category') ?? $serviceModel?->service_category ?? '');
        $allowedCaps = $group !== ''
            ? CanonicalTaxonomy::capabilitiesOf($group)
            : collect(CanonicalTaxonomy::SERVICE_GROUPS)->flatMap(fn ($g) => array_keys($g['capabilities'] ?? []))->unique()->values()->all();

        return [
            ...\App\Support\OrderRequirements::offeringRules(),
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:100'],
            'service_types' => ['nullable', 'array'],
            'service_types.*' => [Rule::in(Service::SERVICE_TYPES)],
            // One of the 4 approved services (CanonicalTaxonomy::SERVICE_GROUPS, 2026-10-14). The order
            // types below are worked out from it on save (Service::deriveOrderTypes), not ticked.
            'service_category' => ['nullable', 'string', Rule::in(CanonicalTaxonomy::serviceGroups())],
            // Garments it works on (old slugs accepted, saved as the garment they were merged into) and the
            // tasks it offers — which must belong to the chosen service.
            'garment_types' => ['nullable', 'array'],
            // A garment from the list, or a name the shop typed under "Other garments" (checked by SUTURA, like a design's).
            'garment_types.*' => ['string', 'max:100'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::in($allowedCaps)],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after_or_equal:sale_starts_at'],
            'estimated_days' => ['nullable', 'integer', 'min:1'],
            'estimated_days_max' => ['nullable', 'integer', 'min:1', 'gt:estimated_days'],
            'min_order_qty' => ['nullable', 'integer', 'min:1'],
            'custom_fields' => ['nullable', 'array'],
            'roster_fields' => ['nullable', 'array'],
            'roster_fields.*.id' => ['required_with:roster_fields', 'string'],
            'roster_fields.*.label' => ['required_with:roster_fields', 'string', 'max:60'],
            'roster_fields.*.type' => ['required_with:roster_fields', 'in:text,number,select'],
            'roster_fields.*.required' => ['nullable', 'boolean'],
            'roster_fields.*.options' => ['nullable', 'array'],
            'pricing_tiers' => ['nullable', 'array'],
            'pricing_tiers.*.label' => ['required', 'string', 'max:100'],
            'pricing_tiers.*.amount' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'size_chart_image_url' => ['nullable', 'string', 'max:2048'],
            'size_chart_columns' => ['nullable', 'array'],
            'size_chart_columns.*' => ['string', 'max:100'],
            'size_chart_rows' => ['nullable', 'array'],
            'size_chart_rows.*.size' => ['required_with:size_chart_rows', 'string', 'max:100'],
            'size_chart_rows.*.values' => ['nullable', 'array'],
            'size_chart_rows.*.values.*' => ['nullable', 'string', 'max:100'],
        ];
    }
}
