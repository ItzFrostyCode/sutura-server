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
        // Fitting and pickup only exist on system-made production rows; nobody can switch a visit to them.
        $existing = $this->route('appointment');
        $types = $existing?->kind === 'production' ? Appointment::TYPES : Appointment::BOOKABLE_TYPES;

        return [
            // Customer and appointment details updatable by owner/manager
            'customer_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'appointment_type' => ['sometimes', 'required', Rule::in($types)],
            'purpose_label' => ['nullable', 'string', 'max:60', 'required_if:appointment_type,other'],
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

            // Staff Check-In action — arrival_status (on_time/early/late) is
            // now auto-derived server-side from this vs scheduled_at
            // whenever checked_in_at is written (see AppointmentController::
            // update()), never trusted from the client and never its own
            // driver of a status transition. Not in Appointment::STATUSES.
            'checked_in_at' => ['nullable', 'date'],

            // Early-Arrival Accommodation workflow (thesis feedback,
            // 2026-09-28) — staff explicitly decides to start service early
            // or ask the customer to keep waiting; scheduled_at itself is
            // never touched by either. See Appointment::EARLY_ARRIVAL_DECISIONS.
            'early_arrival_decision' => ['sometimes', 'nullable', 'in:'.implode(',', Appointment::EARLY_ARRIVAL_DECISIONS)],

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
                function ($attribute, $value, $fail) use ($store) {
                    if (! $value) {
                        return;
                    }
                    $isOwner = (int) $store?->owner_id === (int) $value;
                    if ($isOwner) {
                        return;
                    }
                    $profile = StaffProfile::where('user_id', $value)
                        ->where('store_id', $store?->id)
                        ->first();
                    if (! $profile) {
                        $fail('That handler is not part of this store.');
                        return;
                    }
                    $appointment = $this->route('appointment');
                    $targetBranchId = $this->input('store_branch_id') ?: $appointment?->store_branch_id;
                    if ($targetBranchId && $profile->store_branch_id && (int) $profile->store_branch_id !== (int) $targetBranchId) {
                        $fail('This handler belongs to a different branch than this appointment.');
                    }
                },
            ],

            // What the shop shares with the customer after accepting:
            // a note, multiple reference links, and up to 6 photos.

            // Notes always updatable
            'notes' => ['nullable', 'string', 'max:2000'],
            'job_order_id' => [
                'nullable', 'integer',
                Rule::exists('job_orders', 'id')->where('store_id', $store?->id),
            ],
            'outcome' => ['nullable', 'string', 'in:completed,rescheduled,no_show,converted_to_job,cancelled,no_order'],
            'priority' => ['nullable', 'string', 'in:normal,urgent,rush'],
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform,lab_gown,scrub_suit,corporate_wear,alteration_repair'],
            'fitting_notes' => ['nullable', 'string', 'max:2000'],
            // Written during the consultation; carried onto the production order.
            'visit_notes' => ['nullable', 'string', 'max:4000'],
            'design_source' => ['nullable', Rule::in(Appointment::DESIGN_SOURCES)],
            'design_link' => ['nullable', 'string', 'max:1000', new \App\Rules\SafeLink],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'pieces_count' => ['nullable', 'integer', 'min:1', 'max:5000'],
            // Photos the shop adds during the visit (the customer's own from the booking stay in the same list).
            'reference_images' => ['nullable', 'array', 'max:10'],
            'reference_images.*' => ['string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_at.after' => 'Rescheduled appointment must be in the future.',
        ];
    }
}
