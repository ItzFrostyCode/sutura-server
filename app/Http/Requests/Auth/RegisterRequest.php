<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            // No `unique:users` here — a guest-booking "shadow" account (no
            // real password yet) may already own this email, and the
            // controller lets that case claim the account instead of
            // blocking on a false-positive duplicate.
            'email' => ['required', 'string', 'email', 'max:191'],
            // Password::defaults() (AppServiceProvider) — min 8, mixed
            // case, a number, and a symbol. Matches ProfileController's
            // password-change rule and the frontend's live checklist.
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'string', 'exists:roles,name'],
        ];
    }
}
