<?php

namespace App\Http\Requests\Shop;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shop        = $this->route('shop');
        $branchCount = $shop ? $shop->branches()->count() : 0;

        return [
            'customer_id'      => ['required', 'exists:users,id'],
            'appointment_type' => ['required', 'in:' . implode(',', Appointment::TYPES)],
            'service_id'       => ['nullable', 'exists:services,id'],
            'shop_branch_id'   => $branchCount > 1
                ? ['required', 'exists:shop_branches,id']
                : ['nullable', 'exists:shop_branches,id'],
            'scheduled_at'     => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'assigned_staff_id'=> ['nullable', 'exists:users,id'],
            'notes'            => ['nullable', 'string', 'max:2000'],
            'answers'          => ['nullable', 'array'],
        ];
    }

    /**
     * Conditional validation: service_id is required for measurement, fitting, alteration.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $type      = $this->input('appointment_type');
            $serviceId = $this->input('service_id');

            if (in_array($type, Appointment::TYPES_REQUIRING_SERVICE) && empty($serviceId)) {
                $v->errors()->add(
                    'service_id',
                    "A service is required for appointment type: {$type}."
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'appointment_type.required' => 'Please select an appointment type.',
            'appointment_type.in'       => 'Invalid appointment type.',
            'scheduled_at.after'        => 'The appointment must be scheduled in the future.',
            'shop_branch_id.required'   => 'Please select a branch for this appointment.',
        ];
    }
}
