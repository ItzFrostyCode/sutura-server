<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Which tab of the public Sign In switch was used. Optional so
            // older callers keep working; admins never use this endpoint.
            'portal' => ['nullable', 'string', 'in:customer,store'],
        ];
    }
}
