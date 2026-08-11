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
            // 'integer' added alongside 'exists' on every id field below —
            // MySQL's implicit string-to-int coercion (e.g. '1 OR 1=1' reads
            // as 1) let a non-numeric id string satisfy 'exists' alone, which
            // then crashed downstream with an uncaught TypeError the moment
            // it hit a strictly-typed ?int parameter (Appointment::
            // hasSchedulingConflict()), leaking a stack trace in the response.
            'customer_id'      => ['required', 'integer', 'exists:users,id'],
            'appointment_type' => ['required', 'in:' . implode(',', Appointment::TYPES)],
            'service_id'       => [
                'nullable', 'integer',
                Rule::exists('services', 'id')->where('shop_id', $shop?->id),
            ],
            'shop_branch_id'   => $branchCount > 1
                ? ['required', 'integer', Rule::exists('shop_branches', 'id')->where('shop_id', $shop?->id)]
                : ['nullable', 'integer', Rule::exists('shop_branches', 'id')->where('shop_id', $shop?->id)],
            'scheduled_at'     => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'assigned_staff_id'=> [
                'nullable', 'integer',
                Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop?->id),
                function ($attribute, $value, $fail) use ($shop) {
                    $targetBranchId = $this->input('shop_branch_id');
                    if (!$value || !$targetBranchId) {
                        return;
                    }
                    $staffBranchId = \App\Models\StaffProfile::where('user_id', $value)
                        ->where('shop_id', $shop?->id)
                        ->value('shop_branch_id');
                    if ($staffBranchId && (int) $staffBranchId !== (int) $targetBranchId) {
                        $fail('This staff member belongs to a different branch than the one selected.');
                    }
                },
            ],
            'notes'            => ['nullable', 'string', 'max:2000'],
            'answers'          => ['nullable', 'array'],
            'job_order_id'     => [
                'nullable', 'integer',
                Rule::exists('job_orders', 'id')->where('shop_id', $shop?->id),
            ],
            'outcome'          => ['nullable', 'string', 'in:completed,rescheduled,no_show,converted_to_job,cancelled'],
            'priority'         => ['nullable', 'string', 'in:normal,urgent,rush'],
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform,lab_gown,scrub_suit,corporate_wear,alteration_repair'],
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
