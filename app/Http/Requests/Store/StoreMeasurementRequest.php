<?php

namespace App\Http\Requests\Store;

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
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'source' => ['nullable', 'in:store_owner,customer'],
            'profile_name' => ['required', 'string', 'max:100'],
            'metrics' => ['required', 'array'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
