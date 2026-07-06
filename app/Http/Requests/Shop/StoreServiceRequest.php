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
            'category' => ['nullable', 'string', 'max:100'],
            'service_type' => ['nullable', Rule::in(Service::SERVICE_TYPES)],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'estimated_days' => ['nullable', 'integer', 'min:1'],
            'min_order_qty' => ['nullable', 'integer', 'min:1'],
            'custom_fields' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
