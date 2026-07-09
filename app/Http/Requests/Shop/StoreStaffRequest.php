<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\StaffProfile;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('shop_owner');
    }

    public function rules(): array
    {
        $shop = $this->route('shop');

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
            'shop_branch_id' => [
                'nullable',
                Rule::exists('shop_branches', 'id')->where('shop_id', $shop?->id),
            ],
            'is_branch_manager' => ['sometimes', 'boolean'],
        ];
    }
}
