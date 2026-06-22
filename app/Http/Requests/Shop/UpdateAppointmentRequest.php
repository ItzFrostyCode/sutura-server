<?php

namespace App\Http\Requests\Shop;

use App\Models\Appointment;
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
            // Status transitions — state machine enforced in controller
            'status'           => ['sometimes', 'required', 'in:' . implode(',', Appointment::STATUSES)],

            // Reschedule — updates scheduled_at in-place (no new row)
            'scheduled_at'     => ['sometimes', 'required', 'date', 'after:now'],

            // Duration can be updated if the schedule changes
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:15', 'max:480'],

            // Staff assignment can change before confirmation
            'assigned_staff_id'=> ['sometimes', 'nullable', 'exists:users,id'],

            // Notes always updatable
            'notes'            => ['nullable', 'string', 'max:2000'],
            'job_order_id'     => ['nullable', 'exists:job_orders,id'],

            // NOTE: shop_branch_id is intentionally NOT updatable.
            // Branch is chosen by the customer at booking time and is immutable.
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_at.after' => 'Rescheduled appointment must be in the future.',
        ];
    }
}
