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
            'province' => ['sometimes', 'required', 'string', 'max:100'],
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
        ];
    }
}
