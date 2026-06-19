<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class StoreMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:users,id'],
            'profile_name' => ['required', 'string', 'max:100'],
            'metrics' => ['required', 'array'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
