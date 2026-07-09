<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\StaffProfile;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('shop_owner');
    }

    public function rules(): array
    {
        $staff = $this->route('staff');
        $userId = $staff instanceof \App\Models\StaffProfile ? $staff->user_id : null;
        $shop = $this->route('shop');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:191', 'unique:users,email,' . ($userId ?? 'NULL')],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['sometimes', 'required', Rule::in(StaffProfile::ROLES)],
            'additional_roles' => ['nullable', 'array'],
            'additional_roles.*' => [Rule::in(StaffProfile::ROLES)],
            'specialization' => ['nullable', 'array'],
            'specialization.*' => ['string', 'max:100'],
            'hired_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
            'shop_branch_id' => [
                'nullable',
                Rule::exists('shop_branches', 'id')->where('shop_id', $shop?->id),
            ],
            'is_branch_manager' => ['sometimes', 'boolean'],
        ];
    }
}
