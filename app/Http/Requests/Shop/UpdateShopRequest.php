<?php

namespace App\Http\Requests\Shop;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        $shop = $this->route('shop');
        return $this->user()->id === $shop->owner_id;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:191'],
            'business_type' => ['nullable', 'string', 'max:50'],
            'booking_policy' => ['nullable', 'string'],
            'booking_questions' => ['nullable', 'array'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'social_links' => ['nullable', 'array'],
            'gallery_images' => ['nullable', 'array'],
            'operating_hours' => ['nullable', 'array'],
            'security_deposit' => ['nullable', 'numeric'],
            'rental_duration_days' => ['nullable', 'integer', 'min:1'],
            'overdue_penalty_per_day' => ['nullable', 'numeric'],
            'fitting_fee' => ['nullable', 'numeric'],
            'fitting_limit' => ['nullable', 'integer', 'min:1'],
            'reschedule_fee_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'change_reserved_hours' => ['nullable', 'integer', 'min:0'],
            'change_reserved_fee_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'supported_couriers' => ['nullable', 'array'],
        ];
    }
}
