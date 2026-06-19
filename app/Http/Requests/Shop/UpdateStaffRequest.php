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
        return [
            'role' => ['sometimes', 'required', 'in:head_tailor,tailor,assistant,receptionist'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
