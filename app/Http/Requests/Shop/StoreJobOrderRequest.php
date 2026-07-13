<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\JobOrder;

class StoreJobOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shop = $this->route('shop');

        return [
            'intake_channel' => ['nullable', 'in:walk_in,online'],
            // Store pickup only — the approved thesis explicitly excludes
            // logistics/courier/delivery management from the system's scope.
            'fulfillment_type' => ['nullable', 'in:pickup'],
            'customer_id' => ['required', 'exists:users,id'],
            'service_id' => ['required', 'exists:services,id'],
            'assigned_staff_id' => [
                'nullable',
                Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop?->id),
            ],
            // Same stage model as JobOrderController@assignStaff — settable
            // at creation time too, instead of only via the single
            // assigned_staff_id field (which is now derived from this, not
            // chosen directly, so Create and the Job Detail page share one
            // staffing concept).
            'staff_stages' => ['nullable', 'array'],
            'staff_stages.*.user_id' => [
                'required',
                Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop?->id),
            ],
            'staff_stages.*.stage' => ['required', Rule::in(JobOrder::STAFF_STAGES)],
            'measurement_id' => [
                'nullable',
                Rule::exists('measurements', 'id')->where('shop_id', $shop?->id),
            ],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'balance' => ['required', 'numeric', 'min:0', 'lte:total_amount'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'custom_order_data' => ['nullable', 'array'],
            'custom_order_data.*' => ['nullable'],
            'custom_order_data.team_name' => ['nullable', 'string', 'max:255'],
            'custom_order_data.team_roster' => ['nullable', 'array'],
            'custom_order_data.team_roster.*.name' => ['required_with:custom_order_data.team_roster', 'string', 'max:255'],
            'custom_order_data.team_roster.*.print_name' => ['nullable', 'string', 'max:255'],
            'custom_order_data.team_roster.*.number' => ['nullable', 'string', 'max:100'],
            'custom_order_data.team_roster.*.size' => ['required_with:custom_order_data.team_roster', 'string', 'max:50'],
            'custom_order_data.pre_existing_damage_notes' => ['nullable', 'string', 'max:2000'],
            'shop_branch_id' => ['nullable', 'exists:shop_branches,id'],
            'is_outsourced' => ['nullable', 'boolean'],
            'partner_shop_name' => ['nullable', 'string', 'max:255'],
            'outsourcing_cost' => ['nullable', 'numeric', 'min:0'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            // Design inspiration the customer attached at booking (or the owner
            // captured for a walk-in custom job) — normally inherited automatically
            // from the linked appointment (see JobOrderController@store), but also
            // acceptable directly so a job with no appointment_id can still carry one.
            'reference_images' => ['nullable', 'array', 'max:10'],
            'reference_images.*' => ['string', 'max:1000'],
            'reference_link' => ['nullable', 'string', 'max:500'],
            'material_source' => ['nullable', Rule::in(JobOrder::MATERIAL_SOURCES)],
            'is_rush' => ['nullable', 'boolean'],
            'rush_fee' => ['nullable', 'numeric', 'min:0'],
            'catalog_item_id' => ['nullable', 'exists:catalog_items,id'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
