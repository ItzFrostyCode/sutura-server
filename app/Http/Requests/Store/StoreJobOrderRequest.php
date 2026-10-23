<?php

namespace App\Http\Requests\Store;

use App\Models\JobOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $store = $this->route('store');

        return [
            'intake_channel' => ['nullable', 'in:walk_in,online'],
            // Store pickup only — the approved thesis explicitly excludes
            // logistics/courier/delivery management from the system's scope.
            'fulfillment_type' => ['nullable', 'in:pickup'],
            // 'integer' alongside every 'exists' below — MySQL's implicit
            // string-to-int coercion (e.g. '1 OR 1=1' reads as 1) otherwise
            // lets a non-numeric id string satisfy 'exists' alone, which then
            // crashes downstream the moment it hits a strictly-typed ?int
            // parameter, leaking a stack trace in the response (found and
            // fixed the same way in Store/UpdateAppointmentRequest first).
            // 'exists:users,id' alone doesn't check WHO that user is — a
            // staff/owner/branch_manager/admin's own id passes it just as
            // easily as a real customer's, since job creation only ever
            // checks the id is a real row. Mirrors CustomerController@store's
            // own $isBusinessAccount check, applied here for the same reason:
            // a job order's "customer" should never resolve to a business
            // account.
            'customer_id' => ['required', 'integer', 'exists:users,id', function ($attribute, $value, $fail) {
                $user = User::with('roles:id,name')->find($value);
                $businessRoles = ['store_owner', 'staff', 'branch_manager', 'admin'];
                if ($user && $user->roles->pluck('name')->intersect($businessRoles)->isNotEmpty()) {
                    $fail('This account belongs to a staff or owner user, not a customer.');
                }
            }],
            'service_id' => [
                'required', 'integer',
                Rule::exists('services', 'id')->where('store_id', $store?->id),
            ],
            // assigned_staff_id is not accepted here at all anymore — no more
            // upfront "who's doing this" pre-assignment at creation. It's
            // purely server-derived, set automatically the moment anyone
            // actually moves the job into a production stage (see
            // JobOrderController::update()'s auto-attribution block).
            'measurement_id' => [
                'nullable', 'integer',
                Rule::exists('measurements', 'id')->where('store_id', $store?->id),
            ],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'balance' => ['required', 'numeric', 'min:0', 'lte:total_amount'],
            'payment_method' => ['nullable', 'string', 'in:cash,gcash,paymaya'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'custom_order_data' => ['nullable', 'array'],
            'custom_order_data.*' => ['nullable'],
            'custom_order_data.team_name' => ['nullable', 'string', 'max:255'],
            'custom_order_data.organization' => ['nullable', 'string', 'max:255'],
            'custom_order_data.order_purpose' => ['nullable', 'string', 'max:255'],
            'custom_order_data.garment_type' => ['nullable', 'string', 'max:100'],
            'custom_order_data.garment_component' => ['nullable', 'string', 'in:top_only,bottom_only,full_set,custom_set'],
            'custom_order_data.fabric_preference' => ['nullable', 'string', 'max:100'],
            'custom_order_data.colorway_notes' => ['nullable', 'string', 'max:500'],
            'custom_order_data.neckline_style' => ['nullable', 'string', 'max:100'],
            'custom_order_data.shorts_cut' => ['nullable', 'string', 'max:100'],
            'custom_order_data.artwork_status' => ['nullable', 'string', 'in:not_yet_provided,submitted,for_review,approved,revision_requested'],
            'custom_order_data.total_quantity' => ['nullable', 'integer', 'min:1'],
            'custom_order_data.size_breakdown' => ['nullable', 'array'],
            'custom_order_data.has_personalization' => ['nullable', 'boolean'],
            'custom_order_data.personalization_config' => ['nullable', 'array'],
            'custom_order_data.team_roster' => ['nullable', 'array'],
            'custom_order_data.team_roster.*.name' => ['nullable', 'string', 'max:255'],
            'custom_order_data.team_roster.*.print_name' => ['nullable', 'string', 'max:255'],
            'custom_order_data.team_roster.*.number' => ['nullable', 'string', 'max:100'],
            'custom_order_data.team_roster.*.position' => ['nullable', 'string', 'max:100'],
            'custom_order_data.team_roster.*.role' => ['nullable', 'string', 'max:100'],
            'custom_order_data.team_roster.*.size' => ['nullable', 'string', 'max:50'],
            'custom_order_data.team_roster.*.top_size' => ['nullable', 'string', 'max:50'],
            'custom_order_data.team_roster.*.bottom_size' => ['nullable', 'string', 'max:50'],
            'custom_order_data.team_roster.*.remarks' => ['nullable', 'string', 'max:255'],
            'custom_order_data.team_roster.*.completed' => ['nullable', 'boolean'],
            'custom_order_data.pre_existing_damage_notes' => ['nullable', 'string', 'max:2000'],
            'custom_order_data.spec_table' => ['nullable', 'array'],
            'custom_order_data.spec_table.columns' => ['nullable', 'array'],
            'custom_order_data.spec_table.columns.*.id' => ['required_with:custom_order_data.spec_table.columns', 'string', 'max:100'],
            'custom_order_data.spec_table.columns.*.key' => ['required_with:custom_order_data.spec_table.columns', 'string', 'max:100'],
            'custom_order_data.spec_table.columns.*.label' => ['required_with:custom_order_data.spec_table.columns', 'string', 'max:100'],
            'custom_order_data.spec_table.columns.*.type' => ['nullable', 'string', 'in:text,number,select'],
            'custom_order_data.spec_table.rows' => ['nullable', 'array'],
            'custom_order_data.spec_table.rows.*' => ['nullable', 'array'],
            'custom_order_data.customization_instructions' => ['nullable', 'array'],
            'custom_order_data.customization_instructions.*.id' => ['nullable', 'string', 'max:100'],
            'custom_order_data.customization_instructions.*.label' => ['nullable', 'string', 'max:255'],
            'custom_order_data.customization_instructions.*.details' => ['nullable', 'string', 'max:2000'],
            'custom_order_data.customization_instructions.*.reference_link' => ['nullable', 'string', 'max:500'],
            'custom_order_data.customization_instructions.*.reference_image' => ['nullable', 'string', 'max:1000'],
            'custom_order_data.customization_instructions.*.scope' => ['nullable', 'string', 'max:100'],
            'store_branch_id' => [
                'nullable', 'integer',
                Rule::exists('store_branches', 'id')->where('store_id', $store?->id),
            ],
            'appointment_id' => [
                'nullable', 'integer',
                Rule::exists('appointments', 'id')->where('store_id', $store?->id),
            ],
            // Design inspiration the customer attached at booking (or the owner
            // captured for a walk-in custom job) — normally inherited automatically
            // from the linked appointment (see JobOrderController@store), but also
            // acceptable directly so a job with no appointment_id can still carry one.
            'reference_images' => ['nullable', 'array', 'max:10'],
            'reference_images.*' => ['string', 'max:1000'],
            'reference_link' => ['nullable', 'string', 'max:500', new \App\Rules\SafeLink],
            // Consultation first: where the design came from, a link to the customer's file, and what was said.
            'design_source' => ['nullable', Rule::in(\App\Models\Appointment::DESIGN_SOURCES)],
            'design_link' => ['nullable', 'string', 'max:1000', new \App\Rules\SafeLink],
            'consultation_notes' => ['nullable', 'string', 'max:6000'],
            // More services the same customer asked for at once: each becomes its own linked order.
            'extra_services' => ['nullable', 'array', 'max:6'],
            'extra_services.*.service_id' => ['required', 'integer', Rule::exists('services', 'id')->where('store_id', $store?->id)],
            'extra_services.*.catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('store_id', $store?->id)],
            'extra_services.*.quantity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'extra_services.*.total_amount' => ['nullable', 'numeric', 'min:0'],
            'extra_services.*.notes' => ['nullable', 'string', 'max:1000'],
            'material_source' => ['nullable', Rule::in(JobOrder::MATERIAL_SOURCES)],
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform,lab_gown,scrub_suit,corporate_wear,alteration_repair'],
            'is_rush' => ['nullable', 'boolean'],
            'rush_fee' => ['nullable', 'numeric', 'min:0'],
            'catalog_item_id' => [
                'nullable', 'integer',
                Rule::exists('catalog_items', 'id')->where('store_id', $store?->id),
            ],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            // The completed order this one follows up (a repeat order or a customer's change after pick-up).
            'follow_up_of_id' => [
                'nullable', 'integer',
                Rule::exists('job_orders', 'id')->where('store_id', $store?->id)->whereNull('deleted_at'),
            ],
        ];
    }
}
