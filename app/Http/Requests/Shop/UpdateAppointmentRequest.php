<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'in:pending,confirmed,completed,cancelled'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
