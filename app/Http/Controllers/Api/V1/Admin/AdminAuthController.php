<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * The System Admin portal's own sign-in (the public /auth/login refuses
 * admin accounts). Kept separate so:
 *  - the storefront never shows an admin entry point,
 *  - it can carry a tighter throttle than the customer form (routes/api.php),
 *  - every admin sign-in lands in the audit log,
 *  - its token is named `admin_token`, distinct from storefront sessions.
 * Non-admin credentials get the same generic 401 as a wrong password, so
 * this endpoint can't be used to test which emails exist.
 */
class AdminAuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)
            || ! $user->hasRole('admin') || $user->suspended_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $user->createToken('admin_token', ['admin'])->plainTextToken;

        AdminAudit::record($request, 'admin_signed_in', User::class, $user->id, $user->name, actorId: $user->id);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user->load('roles:id,name'),
                'token' => $token,
            ],
        ]);
    }
}
