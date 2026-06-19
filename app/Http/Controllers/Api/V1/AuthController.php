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
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
        ]);

        $role = Role::where('name', $request->role)->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully.',
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

        $user->load('roles:id,name');
        
        $staffProfile = null;
        if ($user->hasRole('staff')) {
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
        if ($user->hasRole('staff')) {
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
