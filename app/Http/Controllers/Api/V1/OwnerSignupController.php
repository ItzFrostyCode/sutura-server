<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PendingOwnerSignup;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ConfirmOwnerEmailNotification;
use App\Support\ActivityRecorder;
use App\Support\EmailAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Shop-owner sign-up, email first: the owner picks an email and password, we
 * email a link, and the account only exists once the link is opened. The shop
 * itself (identity, business documents, plan) is submitted afterwards from
 * the signed-in onboarding wizard (StoreApplicationController::storeForOwner).
 */
class OwnerSignupController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            // The site's one password rule (AppServiceProvider), same as customer sign-up.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'accept_terms' => ['accepted'],
        ], [
            'accept_terms.accepted' => 'Please agree to the Terms of Service and Privacy Policy to continue.',
        ]);
        $email = Str::lower(trim($data['email']));

        if ($this->emailTaken($email)) {
            return response()->json([
                'success' => false,
                'message' => 'This email is already registered. Please sign in instead.',
                'errors' => ['email' => ['This email is already registered.']],
            ], 422);
        }

        [$signup, $token] = $this->issue($email, Hash::make($data['password']));
        $this->sendLink($signup, $token);

        return response()->json([
            'success' => true,
            'message' => 'Check your inbox for the confirmation link.',
            'data' => ['poll_token' => $signup->poll_token, 'email' => $email, 'resend_in' => PendingOwnerSignup::RESEND_SECONDS],
        ], 201);
    }

    /** The waiting page asks this every few seconds. The poll token is a secret only the signing-up browser holds. */
    public function status(string $pollToken): JsonResponse
    {
        $signup = PendingOwnerSignup::where('poll_token', $pollToken)->first();
        if (! $signup) {
            return response()->json(['success' => false, 'message' => 'This sign-up was not found. Please start again.'], 404);
        }

        $expired = $signup->verified_at === null && $signup->expires_at->isPast();

        return response()->json(['success' => true, 'data' => [
            'email' => $signup->email,
            'status' => $signup->verified_at ? 'verified' : ($expired ? 'expired' : 'pending'),
            'resend_in' => max(0, PendingOwnerSignup::RESEND_SECONDS - (int) $signup->last_sent_at->diffInSeconds(now())),
        ]]);
    }

    public function resend(string $pollToken): JsonResponse
    {
        $signup = PendingOwnerSignup::where('poll_token', $pollToken)->whereNull('verified_at')->first();
        if (! $signup) {
            return response()->json(['success' => false, 'message' => 'This sign-up was not found. Please start again.'], 404);
        }

        $wait = PendingOwnerSignup::RESEND_SECONDS - (int) $signup->last_sent_at->diffInSeconds(now());
        if ($wait > 0) {
            return response()->json(['success' => false, 'message' => "Please wait {$wait}s before sending it again.", 'data' => ['resend_in' => $wait]], 429);
        }

        // A fresh token each time: the older emailed link stops working.
        $token = Str::random(48);
        $signup->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(PendingOwnerSignup::LINK_HOURS),
            'last_sent_at' => now(),
        ])->save();
        $this->sendLink($signup, $token);

        return response()->json(['success' => true, 'message' => 'We sent the link again.', 'data' => ['resend_in' => PendingOwnerSignup::RESEND_SECONDS]]);
    }

    /** Called by the page the emailed button opens. Creates the account; opening it twice is harmless. */
    public function verify(Request $request): JsonResponse
    {
        $token = (string) $request->validate(['token' => ['required', 'string', 'max:100']])['token'];
        $signup = PendingOwnerSignup::where('token_hash', hash('sha256', $token))->first();

        if (! $signup) {
            return response()->json(['success' => false, 'code' => 'invalid_link', 'message' => 'This link is not valid anymore. Please sign up again.'], 422);
        }
        if ($signup->verified_at) {
            return response()->json(['success' => true, 'data' => ['email' => $signup->email]]);
        }
        if ($signup->expires_at->isPast()) {
            return response()->json(['success' => false, 'code' => 'expired_link', 'message' => 'This link has expired. Go back to the sign-up page and send a new one.'], 422);
        }
        if ($this->emailTaken($signup->email)) {
            return response()->json(['success' => false, 'code' => 'email_taken', 'message' => 'This email is already registered. Please sign in instead.'], 422);
        }

        DB::transaction(function () use ($signup) {
            // A guest-booking "shadow" account for this email has no password yet; the owner takes it over.
            $user = User::where('email', $signup->email)->first();
            $fields = [
                'password' => $signup->password,
                'password_set_at' => now(),
                'email_verified_at' => now(),
            ];
            if ($user) {
                $user->forceFill($fields)->save();
            } else {
                $user = new User(['name' => Str::before($signup->email, '@'), 'email' => $signup->email]);
                $user->forceFill($fields)->save();
            }

            $role = Role::where('name', 'store_owner')->firstOrFail();
            $user->roles()->syncWithoutDetaching([$role->id]);

            $signup->forceFill(['verified_at' => now(), 'user_id' => $user->id])->save();
            ActivityRecorder::forUser(request(), $user, 'account_registered', 'Confirmed their email and created a shop owner account');
        });

        return response()->json(['success' => true, 'data' => ['email' => $signup->email]]);
    }

    private function emailTaken(string $email): bool
    {
        return EmailAvailability::taken($email);
    }

    /** @return array{0: PendingOwnerSignup, 1: string} the row and the plain emailed token */
    private function issue(string $email, string $passwordHash): array
    {
        $token = Str::random(48);
        $signup = PendingOwnerSignup::updateOrCreate(['email' => $email], [
            'password' => $passwordHash,
            'token_hash' => hash('sha256', $token),
            'poll_token' => Str::random(48),
            'terms_accepted_at' => now(),
            'terms_version' => PendingOwnerSignup::TERMS_VERSION,
            'expires_at' => now()->addHours(PendingOwnerSignup::LINK_HOURS),
            'last_sent_at' => now(),
            'verified_at' => null,
            'user_id' => null,
        ]);

        return [$signup, $token];
    }

    private function sendLink(PendingOwnerSignup $signup, string $token): void
    {
        // config(), not env(): env() returns null once the config is cached in production.
        $base = rtrim((string) config('app.frontend_url'), '/');
        if (app()->isProduction() && ! str_starts_with($base, 'https://')) {
            Log::warning("FRONTEND_URL is not https ({$base}); the email confirmation link is not secure.");
        }
        $url = $base.'/signup/verify?token='.urlencode($token);
        try {
            Notification::route('mail', $signup->email)->notify(new ConfirmOwnerEmailNotification($url));
        } catch (\Throwable $e) {
            Log::warning("Owner confirmation email failed for {$signup->email}: {$e->getMessage()}");
        }
    }
}
