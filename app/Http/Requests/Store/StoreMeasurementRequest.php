<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:users,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'source' => ['nullable', 'in:store_owner,customer'],
            // What it was measured for: a service of this shop, and optionally one of its designs.
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('store_id', $this->route('store')->id)],
            'catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->where('store_id', $this->route('store')->id)],
            'profile_name' => ['required', 'string', 'max:100'],
            // Typed values, photos of the paper sheet, or both — at least one of them.
            'metrics' => ['required_without:photo_urls', 'nullable', 'array'],
            'photo_urls' => ['nullable', 'array', 'max:6'],
            'photo_urls.*' => ['string', 'max:2048'],
            'notes' => ['nullable', 'string'],
            // finalized = ready to cut from; pending_fitting = needs a fitting check first.
            'status' => ['nullable', 'in:finalized,pending_fitting'],
        ];
    }
}
