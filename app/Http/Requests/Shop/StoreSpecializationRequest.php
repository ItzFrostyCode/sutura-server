<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class StoreSpecializationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('shop_owner') || $this->user()->hasRole('branch_manager');
    }

    public function rules(): array
    {
        return [
            'category'             => ['nullable', 'string', 'max:100'],
            'name'                 => ['required', 'string', 'max:191'],
            'description'          => ['nullable', 'string'],
            'is_active'            => ['sometimes', 'boolean'],
            'starting_price'       => ['nullable', 'numeric', 'min:0'],
            'production_time_days' => ['nullable', 'integer', 'min:0'],
            'min_order_qty'        => ['nullable', 'integer', 'min:1'],
        ];
    }
}
