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
                $user = \App\Models\User::with('roles:id,name')->find($value);
                $businessRoles = ['shop_owner', 'staff', 'branch_manager', 'admin'];
                if ($user && $user->roles->pluck('name')->intersect($businessRoles)->isNotEmpty()) {
                    $fail('This account belongs to a staff or owner user, not a customer.');
                }
            }],
            'service_id' => [
                'required', 'integer',
                Rule::exists('services', 'id')->where('shop_id', $shop?->id),
            ],
            'assigned_staff_id' => [
                'nullable', 'integer',
                Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop?->id),
                $this->staffBranchMatchesRule($shop),
            ],
            // Same stage model as JobOrderController@assignStaff — settable
            // at creation time too, instead of only via the single
            // assigned_staff_id field (which is now derived from this, not
            // chosen directly, so Create and the Job Detail page share one
            // staffing concept).
            'staff_stages' => ['nullable', 'array'],
            'staff_stages.*.user_id' => [
                'required', 'integer',
                Rule::exists('staff_profiles', 'user_id')->where('shop_id', $shop?->id),
                $this->staffBranchMatchesRule($shop),
            ],
            'staff_stages.*.stage' => ['required', Rule::in(JobOrder::STAFF_STAGES)],
            'measurement_id' => [
                'nullable', 'integer',
                Rule::exists('measurements', 'id')->where('shop_id', $shop?->id),
            ],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'balance' => ['required', 'numeric', 'min:0', 'lte:total_amount'],
            'payment_method' => ['nullable', 'string', 'in:cash,gcash,bank_transfer'],
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
            'shop_branch_id' => [
                'nullable', 'integer',
                Rule::exists('shop_branches', 'id')->where('shop_id', $shop?->id),
            ],
            'is_outsourced' => ['nullable', 'boolean'],
            'partner_shop_name' => ['nullable', 'string', 'max:255'],
            'outsourcing_cost' => ['nullable', 'numeric', 'min:0'],
            'appointment_id' => [
                'nullable', 'integer',
                Rule::exists('appointments', 'id')->where('shop_id', $shop?->id),
            ],
            // Design inspiration the customer attached at booking (or the owner
            // captured for a walk-in custom job) — normally inherited automatically
            // from the linked appointment (see JobOrderController@store), but also
            // acceptable directly so a job with no appointment_id can still carry one.
            'reference_images' => ['nullable', 'array', 'max:10'],
            'reference_images.*' => ['string', 'max:1000'],
            'reference_link' => ['nullable', 'string', 'max:500'],
            'material_source' => ['nullable', Rule::in(JobOrder::MATERIAL_SOURCES)],
            'garment_category' => ['nullable', 'string', 'in:barong,gown,suit,filipiniana,uniform,lab_gown,scrub_suit,corporate_wear,alteration_repair'],
            'is_rush' => ['nullable', 'boolean'],
            'rush_fee' => ['nullable', 'numeric', 'min:0'],
            'catalog_item_id' => [
                'nullable', 'integer',
                Rule::exists('catalog_items', 'id')->where('shop_id', $shop?->id),
            ],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Only fires when shop_branch_id is explicitly present in the request —
     * when it's omitted, JobOrderController@store derives it afterward
     * (creator's own branch, or the shop's main branch), which this
     * FormRequest can't see yet. Staff with no fixed branch (shop_branch_id
     * null on their staff_profiles row) are floaters and always pass.
     */
    private function staffBranchMatchesRule(?\App\Models\Shop $shop): \Closure
    {
        return function ($attribute, $value, $fail) use ($shop) {
            $targetBranchId = $this->input('shop_branch_id');
            if (!$targetBranchId || !$value) {
                return;
            }
            $staffBranchId = \App\Models\StaffProfile::where('user_id', $value)
                ->where('shop_id', $shop?->id)
                ->value('shop_branch_id');
            if ($staffBranchId && (int) $staffBranchId !== (int) $targetBranchId) {
                $fail('This staff member belongs to a different branch than the one selected.');
            }
        };
    }
}
