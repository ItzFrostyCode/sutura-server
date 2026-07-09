<?php

namespace App\Http\Requests\Shop;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('shop_owner');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:100'],
            'service_types' => ['nullable', 'array'],
            'service_types.*' => [Rule::in(Service::SERVICE_TYPES)],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after_or_equal:sale_starts_at'],
            'estimated_days' => ['nullable', 'integer', 'min:1'],
            'min_order_qty' => ['nullable', 'integer', 'min:1'],
            'custom_fields' => ['nullable', 'array'],
            'pricing_tiers' => ['required', 'array', 'min:1'],
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
