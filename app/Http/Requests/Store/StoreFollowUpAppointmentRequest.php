<?php

namespace App\Http\Requests\Store;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately narrower than StoreAppointmentRequest — Staff may create an
 * operational follow-up visit (consultation/fitting/adjustment/pickup)
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
        $branchCount = $store ? $store->branches()->count() : 0;

        return [
            // At least one of customer_id/job_order_id is required — enforced
            // in the controller, since "required_without" alone can't express
            // "customer_id is derivable from job_order_id" cleanly here.
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'job_order_id' => [
                'nullable', 'integer',
                Rule::exists('job_orders', 'id')->where('store_id', $store?->id),
            ],
            'appointment_type' => ['required', 'in:'.implode(',', Appointment::TYPES)],
            'scheduled_at' => ['required', 'date', 'after_or_equal:today'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'store_branch_id' => $branchCount > 1
                ? ['required', 'integer', Rule::exists('store_branches', 'id')->where('store_id', $store?->id)]
                : ['nullable', 'integer', Rule::exists('store_branches', 'id')->where('store_id', $store?->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'appointment_type.required' => 'Please select what this visit is for.',
            'scheduled_at.after_or_equal' => 'The follow-up must be scheduled today or later.',
            'store_branch_id.required' => 'Please select a branch for this visit.',
        ];
    }
}
