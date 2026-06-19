<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_staff_id' => ['nullable', 'exists:users,id'],
            'measurement_id' => ['nullable', 'exists:measurements,id'],
            'balance' => ['sometimes', 'numeric', 'min:0'],
            'payment_status' => ['sometimes', 'in:unpaid,partial,paid'],
            'status' => ['sometimes', 'in:pending,cutting,sewing,fitting,ready_for_pickup,completed,cancelled'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
