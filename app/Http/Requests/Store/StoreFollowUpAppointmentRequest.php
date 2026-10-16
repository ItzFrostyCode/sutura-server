<?php

namespace App\Http\Requests\Store;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately narrower than StoreAppointmentRequest — Staff may create an
 * operational follow-up visit (consultation, measurement, alteration or other)
 * without the full owner/manager booking form's fields (payment_method,
 * answers, priority, etc.). See docs/CUSTOMER-WORKFLOW.md §7.4,
 * docs/STAFF-WORKFLOW.md §17.
 */
class StoreFollowUpAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $store = $this->route('store');
        return [
            // At least one of customer_id/job_order_id is required — enforced
            // in the controller, since "required_without" alone can't express
            // "customer_id is derivable from job_order_id" cleanly here.
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'job_order_id' => [
                'nullable', 'integer',
                Rule::exists('job_orders', 'id')->where('store_id', $store?->id),
            ],
            // Fitting and pickup are made from a production order, never typed in.
            'appointment_type' => ['required', Rule::in(Appointment::BOOKABLE_TYPES)],
            'on_conflict' => ['nullable', Rule::in(Appointment::ON_CONFLICT)],
            'purpose_label' => ['nullable', 'string', 'max:60', 'required_if:appointment_type,other'],
            'scheduled_at' => ['required', 'date', 'after_or_equal:today'],
            // No branch field: the server books the visit at the branch that has the customer's order.
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'appointment_type.required' => 'Please select what this visit is for.',
            'appointment_type.in' => 'Fitting and pickup are made from a production order, not booked here.',
            'scheduled_at.after_or_equal' => 'The follow-up must be scheduled today or later.',
        ];
    }
}
