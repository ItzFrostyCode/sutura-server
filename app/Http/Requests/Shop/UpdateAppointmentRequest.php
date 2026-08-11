<?php

namespace App\Http\Requests\Shop;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shop = $this->route('shop');

        return [
            // Status transitions — state machine enforced in controller
            'status'           => ['sometimes', 'required', 'in:' . implode(',', Appointment::STATUSES)],

            // Reschedule — updates scheduled_at in-place (no new row)
            'scheduled_at'     => ['sometimes', 'required', 'date', 'after:now'],

            // Duration can be updated if the schedule changes
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:15', 'max:480'],

            // Staff assignment can change before confirmation
            'assigned_staff_id'=> [
                'sometimes', 'nullable', 'integer',
                Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop?->id),
                function ($attribute, $value, $fail) use ($shop) {
                    $appointment = $this->route('appointment');
                    $targetBranchId = $appointment?->shop_branch_id;
                    if (!$value || !$targetBranchId) {
                        return;
                    }
                    $staffBranchId = \App\Models\StaffProfile::where('user_id', $value)
                        ->where('shop_id', $shop?->id)
                        ->value('shop_branch_id');
                    if ($staffBranchId && (int) $staffBranchId !== (int) $targetBranchId) {
                        $fail('This staff member belongs to a different branch than this appointment.');
                    }
                },
            ],

            // Notes always updatable
            'notes'            => ['nullable', 'string', 'max:2000'],
            'job_order_id'     => [
                'nullable', 'integer',
                Rule::exists('job_orders', 'id')->where('shop_id', $shop?->id),
            ],
            'outcome'          => ['nullable', 'string', 'in:completed,rescheduled,no_show,converted_to_job,cancelled'],
            'priority'         => ['nullable', 'string', 'in:normal,urgent,rush'],
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform,lab_gown,scrub_suit,corporate_wear,alteration_repair'],
            'fitting_notes'    => ['nullable', 'string', 'max:2000'],

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
