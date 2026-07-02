<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class StoreJobOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'intake_channel' => ['nullable', 'in:walk_in,online'],
            'fulfillment_type' => ['nullable', 'in:pickup,shipping'],
            'customer_id' => ['required', 'exists:users,id'],
            'service_id' => ['required', 'exists:services,id'],
            'assigned_staff_id' => ['nullable', 'exists:users,id'],
            'measurement_id' => ['nullable', 'exists:measurements,id'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'balance' => ['required', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'shipping_address' => ['nullable', 'string', 'max:500'],
            'courier_name' => ['nullable', 'string', 'max:100'],
            'courier_tracking_number' => ['nullable', 'string', 'max:100'],
            'custom_order_data' => ['nullable', 'array'],
            'custom_order_data.*' => ['nullable'],
            'custom_order_data.team_name' => ['nullable', 'string', 'max:255'],
            'custom_order_data.team_roster' => ['nullable', 'array'],
            'custom_order_data.team_roster.*.name' => ['required_with:custom_order_data.team_roster', 'string', 'max:255'],
            'custom_order_data.team_roster.*.print_name' => ['nullable', 'string', 'max:255'],
            'custom_order_data.team_roster.*.number' => ['nullable', 'string', 'max:100'],
            'custom_order_data.team_roster.*.size' => ['required_with:custom_order_data.team_roster', 'string', 'max:50'],
            'shop_branch_id' => ['nullable', 'exists:shop_branches,id'],
            'is_outsourced' => ['nullable', 'boolean'],
            'partner_shop_name' => ['nullable', 'string', 'max:255'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'is_rush' => ['nullable', 'boolean'],
            'rush_fee' => ['nullable', 'numeric', 'min:0'],
            'catalog_item_id' => ['nullable', 'exists:catalog_items,id'],
        ];
    }
}
