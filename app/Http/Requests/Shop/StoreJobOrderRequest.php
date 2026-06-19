<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class StoreJobOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:users,id'],
            'service_id' => ['required', 'exists:services,id'],
            'assigned_staff_id' => ['nullable', 'exists:users,id'],
            'measurement_id' => ['nullable', 'exists:measurements,id'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'balance' => ['required', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
