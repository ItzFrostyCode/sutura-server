<?php

namespace App\Http\Requests\Auth;

use Illuminate\Support\Arr;

/**
 * The same shop application, sent by an owner who is already signed in: their account email is the contact, so no
 * `email` is asked for again.
 */
class OwnerStoreApplicationRequest extends StoreApplicationRequest
{
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['email']);
    }
}
