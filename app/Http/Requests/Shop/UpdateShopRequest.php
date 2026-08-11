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
            'logo_path' => ['nullable', 'string'],
            'banner_path' => ['nullable', 'string'],
            // Never actually validated (or persisted — see the
            // add_gallery_images_to_shops_table migration) despite a full
            // upload UI existing for it. 'billing' page in this app markets
            // gallery photos as "unlimited" (no plan differentiates on it),
            // but unbounded is still a real payload-size/abuse vector — 100
            // is generous for a shop's real portfolio without being a
            // meaningful product limit.
            'gallery_images' => ['nullable', 'array', 'max:100'],
            'gallery_images.*' => ['string', 'max:1000'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:191'],
            'business_type' => ['nullable', 'string', 'max:50'],
            'booking_policy' => ['nullable', 'string'],
            'booking_questions' => ['nullable', 'array'],
            'max_appointments_per_day' => ['nullable', 'integer', 'min:1'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'social_links' => ['nullable', 'array'],
            'operating_hours' => ['nullable', 'array'],
            // How many fitting appointments a job order gets before the shop
            // starts charging an extra fitting fee — see JobOrderController's
            // fitting-count enforcement. Not a rental concept.
            'fitting_fee' => ['nullable', 'numeric'],
            'fitting_limit' => ['nullable', 'integer', 'min:1'],
            'specializations' => ['nullable', 'array'],
            'specializations.*' => ['string', 'in:barong,gown,suit,filipiniana,uniform,lab_gown,scrub_suit,corporate_wear,alteration_repair'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_hidden' => ['sometimes', 'boolean'],
            // Where customers should actually send a GCash/bank payment —
            // informational only, the system still never moves money itself.
            'gcash_number' => ['nullable', 'string', 'max:20'],
            'gcash_account_name' => ['nullable', 'string', 'max:191'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'bank_account_name' => ['nullable', 'string', 'max:191'],
        ];
    }
}
