<?php

namespace App\Http\Requests\Shop;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'assigned_staff_id'=> [
                'nullable',
                Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop?->id),
            ],
            'notes'            => ['nullable', 'string', 'max:2000'],
            'answers'          => ['nullable', 'array'],
            'job_order_id'     => ['nullable', 'exists:job_orders,id'],
            'outcome'          => ['nullable', 'string', 'in:completed,rescheduled,no_show,converted_to_job,cancelled'],
            'priority'         => ['nullable', 'string', 'in:normal,urgent,rush'],
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform'],
        ];
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
