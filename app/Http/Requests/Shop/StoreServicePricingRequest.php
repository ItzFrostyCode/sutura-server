<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class StoreServicePricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('shop_owner');
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:191'],
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
