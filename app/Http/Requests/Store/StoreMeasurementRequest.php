<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

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
            'source' => ['nullable', 'in:store_owner,customer'],
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
