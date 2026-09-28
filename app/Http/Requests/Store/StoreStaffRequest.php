<?php

namespace App\Http\Requests\Store;

use App\Models\StaffProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Coarse role check only — the real "your own branch, never another
        // branch manager, never a promotion" scoping for a branch_manager
        // caller lives in StaffController::staffManagerCrudDenied(), which
        // needs the route-bound Store/StaffProfile this request class
        // doesn't have access to at authorization time.
        return $this->user()->hasRole('store_owner') || $this->user()->hasRole('branch_manager');
    }

    public function rules(): array
    {
        $store = $this->route('store');

        return [
            'name' => ['required', 'string', 'max:191'],
            // No `unique:users` — a guest-booking "shadow" account (no real
            // password yet) may already own this email; the controller lets
            // that case be claimed as this staff account instead of blocking.
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in(StaffProfile::ROLES)],
            // Ranked secondary roles — index 0 is rank 2, index 1 is rank 3, etc.
            // Lets one versatile staff member cover multiple roles without a
            // separate duplicate account per role.
            'additional_roles' => ['nullable', 'array'],
            'additional_roles.*' => [Rule::in(StaffProfile::ROLES)],
            'specialization' => ['nullable', 'array'],
            'specialization.*' => ['string', 'max:100'],
            'hired_at' => ['nullable', 'date'],
            'store_branch_id' => [
                // A branch manager scoped to no branch would functionally
                // act with no branch restriction at all — every
                // branch-scoping check in this codebase (JobOrderController,
                // StaffController) reads staffProfile->store_branch_id, and
                // null there means "sees everything," the same as an owner.
                // Granting manager authority must pin them to one branch.
                Rule::requiredIf(fn () => $this->boolean('is_branch_manager')),
                'nullable',
                Rule::exists('store_branches', 'id')->where('store_id', $store?->id),
            ],
            'is_branch_manager' => ['sometimes', 'boolean'],
            // Model/migration have carried this column since day one, but
            // nothing ever validated or wrote to it — a real "structurally
            // dead" field, same bug class as CatalogOrderController's
            // payment fields found earlier. Owner-set, short profile note
            // ("15 years in bespoke suits, specializes in barong") shown on
            // the Staff Profile view.
            'bio' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
