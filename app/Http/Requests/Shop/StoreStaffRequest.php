<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'email' => ['required', 'string', 'email', 'max:191', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'in:head_tailor,tailor,cutter,seamstress,assistant,receptionist,quality_control,subcontractor,designer,pattern_maker'],
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
