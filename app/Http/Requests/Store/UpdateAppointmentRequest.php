<?php

namespace App\Http\Requests\Store;

use App\Models\Appointment;
use App\Models\StaffProfile;
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
        $store = $this->route('store');

        return [
            // Customer and appointment details updatable by owner/manager
            'customer_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'appointment_type' => ['sometimes', 'required', 'in:'.implode(',', Appointment::TYPES)],
            'service_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('services', 'id')->where('store_id', $store?->id),
            ],
            'store_branch_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('store_branches', 'id')->where('store_id', $store?->id),
            ],

            // Status transitions — state machine enforced in controller
            'status' => ['sometimes', 'required', 'in:'.implode(',', Appointment::STATUSES)],

            // Staff Check-In action — On Time/Late is derived from this vs
            // scheduled_at on the frontend/response side, never its own
            // stored status. Not in Appointment::STATUSES, not a new column
            // driving any transition — see docs/STAFF-WORKFLOW.md §1-2.
            'checked_in_at' => ['nullable', 'date'],

            // Reschedule — updates scheduled_at in-place (no new row)
            'scheduled_at' => [
                'sometimes', 'required', 'date',
                function ($attribute, $value, $fail) {
                    $appointment = $this->route('appointment');
                    // Only enforce future/today check if the scheduled_at is being changed
                    if ($appointment && $appointment->scheduled_at && strtotime($value) !== strtotime($appointment->scheduled_at)) {
                        if (strtotime($value) < strtotime('today')) {
                            $fail('Rescheduled appointment must not be in the past.');
                        }
                    }
                },
            ],

            // Duration can be updated if the schedule changes
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:15', 'max:480'],

            // Staff assignment can change before confirmation
            'assigned_staff_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('staff_profiles', 'user_id')->where('store_id', $store?->id),
                function ($attribute, $value, $fail) use ($store) {
                    $appointment = $this->route('appointment');
                    $targetBranchId = $this->input('store_branch_id') ?: $appointment?->store_branch_id;
                    if (! $value || ! $targetBranchId) {
                        return;
                    }
                    $staffBranchId = StaffProfile::where('user_id', $value)
                        ->where('store_id', $store?->id)
                        ->value('store_branch_id');
                    if ($staffBranchId && (int) $staffBranchId !== (int) $targetBranchId) {
                        $fail('This staff member belongs to a different branch than this appointment.');
                    }
                },
            ],

            // Notes always updatable
            'notes' => ['nullable', 'string', 'max:2000'],
            'job_order_id' => [
                'nullable', 'integer',
                Rule::exists('job_orders', 'id')->where('store_id', $store?->id),
            ],
            'outcome' => ['nullable', 'string', 'in:completed,rescheduled,no_show,converted_to_job,cancelled'],
            'priority' => ['nullable', 'string', 'in:normal,urgent,rush'],
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform,lab_gown,scrub_suit,corporate_wear,alteration_repair'],
            'fitting_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_at.after' => 'Rescheduled appointment must be in the future.',
        ];
    }
}
