<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $existing = User::where('email', $request->email)->first();

        // An existing row with no real password yet is a "shadow" account —
        // auto-created by a guest booking with a random, never-communicated
        // password. Let this registration claim it instead of bouncing on a
        // false-positive duplicate-email error; a fully-claimed account
        // (owner, staff, or an already-registered customer) is still blocked.
        if ($existing && $existing->password_set_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This email is already registered. Please log in instead.',
                'errors'  => ['email' => ['This email is already registered.']],
            ], 422);
        }

        if ($existing) {
            $user = $existing;
            $user->update([
                'name'            => $request->name,
                'password'        => Hash::make($request->password),
                'password_set_at' => now(),
                'phone'           => $request->phone ?? $user->phone,
            ]);
        } else {
            $user = User::create([
                'name'            => $request->name,
                'email'           => $request->email,
                'password'        => Hash::make($request->password),
                'password_set_at' => now(),
                'phone'           => $request->phone,
            ]);
        }

        $role = Role::where('name', $request->role)->first();
        if ($role && !$user->hasRole($role->name)) {
            $user->roles()->attach($role->id);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => $existing ? 'Account activated — your previous booking history is now linked to this account.' : 'Account created successfully.',
            'data' => [
                'user' => $user->load('roles:id,name'),
                'token' => $token,
            ]
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $user->load('roles:id,name', 'shops');
        
        $staffProfile = null;
        if ($user->hasRole('staff') || $user->hasRole('branch_manager')) {
            $user->load(['staffProfile.shop', 'staffProfile.branch']);
            if ($user->staffProfile) {
                $staffProfile = $user->staffProfile;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'staff_profile' => $staffProfile,
                'shop' => $user->shops->first(),
                'token' => $token,
            ]
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out.'
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles:id,name', 'shops');
        
        $staffProfile = null;
        if ($user->hasRole('staff') || $user->hasRole('branch_manager')) {
            $user->load(['staffProfile.shop', 'staffProfile.branch']);
            if ($user->staffProfile) {
                $staffProfile = $user->staffProfile;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'staff_profile' => $staffProfile,
                'shop' => $user->shops->first() // For shop owners
            ]
        ]);
    }
}
