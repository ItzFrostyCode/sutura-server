<?php

namespace App\Http\Requests\Auth;

use App\Support\ShopLogin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'email.not_regex' => 'Shop logins can\'t be used for a customer account. Sign up with your personal email.',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            // No `unique:users` here — a guest-booking "shadow" account (no
            // real password yet) may already own this email, and the
            // controller lets that case claim the account instead of
            // blocking on a false-positive duplicate.
            'email' => ['required', 'string', 'email', 'max:191', 'not_regex:/@'.preg_quote(ShopLogin::domain(), '/').'$/i'],
            // Password::defaults() (AppServiceProvider) — min 8, mixed
            // case, a number, and a symbol. Matches ProfileController's
            // password-change rule and the frontend's live checklist.
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:20'],
            // Was `exists:roles,name`, which let anyone POST role=admin and
            // self-register a System Admin account. Self-signup is customers
            // only now: store owners go through /store-applications (admin
            // reviewed), staff are created by their owner, admins are seeded.
            'role' => ['required', 'string', Rule::in(['customer'])],
        ];
    }
}
