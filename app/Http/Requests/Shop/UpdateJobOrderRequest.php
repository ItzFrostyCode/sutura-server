<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\JobOrder;

class UpdateJobOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shop = $this->route('shop');

        return [
            'intake_channel' => ['sometimes', 'in:walk_in,online'],
            // Store pickup only — the approved thesis explicitly excludes
            // logistics/courier/delivery management from the system's scope.
            'fulfillment_type' => ['sometimes', 'in:pickup'],
            'assigned_staff_id' => [
                'nullable',
                Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop?->id),
            ],
            'measurement_id' => [
                'nullable',
                Rule::exists('measurements', 'id')->where('shop_id', $shop?->id),
            ],
            // balance/payment_status are intentionally NOT editable here — they must
            // only move through JobOrderController@pay, which recomputes the balance
            // from the current DB value inside one request instead of trusting
            // whatever stale figure the client happened to have loaded. Accepting
            // them here would let a stale tab silently overwrite a payment another
            // user just recorded (or let anyone mark a job "paid" with no ledger entry).
            'status' => ['sometimes', Rule::in(JobOrder::STATUSES)],
            'cancellation_reason' => ['nullable', 'string', Rule::in(JobOrder::CANCELLATION_REASONS), 'required_if:status,cancelled'],
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
            'shop_branch_id' => ['nullable', 'exists:shop_branches,id'],
            'is_outsourced' => ['sometimes', 'boolean'],
            'partner_shop_name' => ['nullable', 'string', 'max:255'],
            'outsourcing_cost' => ['nullable', 'numeric', 'min:0'],
            'is_rush' => ['sometimes', 'boolean'],
            'rush_fee' => ['sometimes', 'numeric', 'min:0'],
            'catalog_item_id' => ['nullable', 'exists:catalog_items,id'],
            'completion_photo_url' => ['nullable', 'string', 'max:500'],
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
            // Lets a job with no appointment_id (e.g. a walk-in custom order) still
            // get a reference photo/link attached after the fact, not just at creation.
            'reference_images' => ['nullable', 'array', 'max:10'],
            'reference_images.*' => ['string', 'max:1000'],
            'reference_link' => ['nullable', 'string', 'max:500'],
            'material_source' => ['nullable', Rule::in(JobOrder::MATERIAL_SOURCES)],
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform'],
            'hold_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
