<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enforce 191-character default string length so MariaDB / MySQL on XAMPP
        // does not exceed the 1000-byte index limit on utf8mb4 unique/primary keys.
        Schema::defaultStringLength(191);

        // Point password-reset emails at the Next.js reset page instead of
        // Laravel's default backend-only URL — there is no server-rendered
        // reset view in this API-only app.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontendUrl = rtrim(config('app.frontend_url'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($notifiable->getEmailForPasswordReset());
        });

        // Single source of truth for "what counts as a strong password" —
        // Password::defaults() is already referenced by ProfileController's
        // password-change validation; before this it fell back to Laravel's
        // bare min(8) with no complexity requirement. Registration
        // (RegisterRequest) now uses this same rule instead of its own
        // plain 'min:8', so the frontend's password checklist (8+ chars,
        // number+symbol, upper+lowercase) matches what the server actually
        // enforces everywhere a password is set, not just decorative UI copy.
        Password::defaults(function () {
            return Password::min(8)->mixedCase()->numbers()->symbols();
        });
    }
}
