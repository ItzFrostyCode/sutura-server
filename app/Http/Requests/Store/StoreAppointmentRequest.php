<?php

namespace App\Http\Requests\Store;

use App\Models\Appointment;
use App\Models\StaffProfile;
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
        $store = $this->route('store');
        $branchCount = $store ? $store->branches()->count() : 0;

        return [
            // 'integer' added alongside 'exists' on every id field below —
            // MySQL's implicit string-to-int coercion (e.g. '1 OR 1=1' reads
            // as 1) let a non-numeric id string satisfy 'exists' alone, which
            // then crashed downstream with an uncaught TypeError the moment
            // it hit a strictly-typed ?int parameter (Appointment::
            // hasSchedulingConflict()), leaking a stack trace in the response.
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            // Service (for one of the shop's services) or Shop (a general visit). Production is system-made only.
            'kind' => ['required', Rule::in(Appointment::BOOKABLE_KINDS)],
            // A service visit's purpose follows the service; a shop visit is consultation, measurement or other.
            // Fitting and pickup are made by the system for a production order, never typed in.
            'appointment_type' => ['nullable', Rule::in(Appointment::BOOKABLE_TYPES)],
            'purpose_label' => ['nullable', 'string', 'max:60', 'required_if:appointment_type,other'],
            'service_id' => [
                'nullable', 'integer', 'required_if:kind,service',
                Rule::exists('services', 'id')->where('store_id', $store?->id),
            ],
            // What to do when a customer's online request is waiting for this time (the owner decides).
            'on_conflict' => ['nullable', Rule::in(Appointment::ON_CONFLICT)],
            // The visit happens at the branch the shop user is working in; the server fills it in when it is not sent.
            'store_branch_id' => ['nullable', 'integer', Rule::exists('store_branches', 'id')->where('store_id', $store?->id)],
            'scheduled_at' => ['required', 'date', 'after_or_equal:today'],
            'assigned_staff_id' => [
                'nullable', 'integer',
                Rule::exists('staff_profiles', 'user_id')->where('store_id', $store?->id),
                function ($attribute, $value, $fail) use ($store) {
                    $targetBranchId = $this->input('store_branch_id');
                    if (! $value || ! $targetBranchId) {
                        return;
                    }
                    $staffBranchId = StaffProfile::where('user_id', $value)
                        ->where('store_id', $store?->id)
                        ->value('store_branch_id');
                    if ($staffBranchId && (int) $staffBranchId !== (int) $targetBranchId) {
                        $fail('This staff member belongs to a different branch than the one selected.');
                    }
                },
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'answers' => ['nullable', 'array'],
            'job_order_id' => [
                'nullable', 'integer',
                Rule::exists('job_orders', 'id')->where('store_id', $store?->id),
            ],
            'outcome' => ['nullable', 'string', 'in:completed,rescheduled,no_show,converted_to_job,cancelled'],
            'priority' => ['nullable', 'string', 'in:normal,urgent,rush'],
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform,lab_gown,scrub_suit,corporate_wear,alteration_repair'],
        ];
    }

    public function messages(): array
    {
        return [
            'kind.required' => 'Please choose Service or Shop.',
            'service_id.required_if' => 'Please choose the service this visit is for.',
            'appointment_type.in' => 'Fitting and pickup are made from a production order, not booked here.',
            'scheduled_at.after' => 'The appointment must be scheduled in the future.',
        ];
    }
}
