<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

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

        return [
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:191', 'unique:users,email,' . ($userId ?? 'NULL')],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['sometimes', 'required', 'in:head_tailor,tailor,cutter,seamstress,assistant,receptionist,quality_control'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'hired_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ];
    }
}
