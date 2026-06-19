<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // We will handle authorization in the controller or assume authenticated user
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:users,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
