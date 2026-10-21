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
            // Optional for a consultation that starts now: with no customer a walk-in placeholder is made (see WalkInCustomer).
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'walk_in_name' => ['nullable', 'string', 'max:120'],
            'walk_in_phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (\App\Support\PhoneNumber::normalize((string) $value) === null) {
                    $fail('Enter an 11-digit Philippine mobile number starting with 09 (e.g. 0977 704 4683).');
                }
            }],
            'design_source' => ['nullable', Rule::in(Appointment::DESIGN_SOURCES)],
            'design_link' => ['nullable', 'string', 'max:1000', new \App\Rules\SafeLink],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'pieces_count' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'visit_notes' => ['nullable', 'string', 'max:4000'],
            // Service (for one of the shop's services) or Shop (a general visit). Production is system-made only.
            'kind' => ['required', Rule::in(Appointment::BOOKABLE_KINDS)],
            // A service visit's purpose follows the service; a shop visit is consultation, measurement or other.
            // Fitting and pickup are made by the system for a production order, never typed in.
            'appointment_type' => [
                'nullable', Rule::in(Appointment::BOOKABLE_TYPES),
                // Measuring or altering needs a service; a general shop visit can only be a consultation or "other".
                function ($attribute, $value, $fail) {
                    if ($this->input('kind') === 'shop' && in_array($value, Appointment::TYPES_REQUIRING_SERVICE, true)) {
                        $fail('Choose a service for a measurement or alteration visit.');
                    }
                },
            ],
            'purpose_label' => ['nullable', 'string', 'max:60', 'required_if:appointment_type,other'],
            'service_id' => [
                'nullable', 'integer', 'required_if:kind,service',
                Rule::exists('services', 'id')->where('store_id', $store?->id),
            ],
            'catalog_item_id' => [
                'nullable', 'integer',
                Rule::exists('catalog_items', 'id')->where('store_id', $store?->id),
            ],
            'selected_size' => ['nullable', 'string', 'max:50'],
            'selected_color' => ['nullable', 'string', 'max:50'],
            // One service (and optionally one of its designs) per appointment.
            'items' => ['nullable', 'array', 'max:1'],
            'items.*.service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('store_id', $store?->id)],
            'items.*.catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('store_id', $store?->id)],
            'items.*.item_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.selected_size' => ['nullable', 'string', 'max:50'],
            'items.*.selected_color' => ['nullable', 'string', 'max:50'],
            'items.*.add_ons' => ['nullable', 'array'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            // What to do when a customer's online request is waiting for this time (the owner decides).
            'on_conflict' => ['nullable', Rule::in(Appointment::ON_CONFLICT)],
            // The visit happens at the branch the shop user is working in; the server fills it in when it is not sent.
            'store_branch_id' => ['nullable', 'integer', Rule::exists('store_branches', 'id')->where('store_id', $store?->id)],
            'scheduled_at' => ['required', 'date', 'after_or_equal:today'],
            'assigned_staff_id' => [
                'nullable', 'integer',
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
                    $targetBranchId = $this->input('store_branch_id');
                    if ($targetBranchId && $profile->store_branch_id && (int) $profile->store_branch_id !== (int) $targetBranchId) {
                        $fail('This handler belongs to a different branch than the one selected.');
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
