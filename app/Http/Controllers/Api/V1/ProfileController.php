<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    /**
     * Update the user's personal details.
     */
    public function updatePersonal(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $user->fresh()
        ]);
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.'
        ]);
    }

    /**
     * Toggle Staff Availability.
     */
    public function toggleAvailability(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('staff') || !$user->staffProfile) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'is_available' => 'required|boolean'
        ]);

        $user->staffProfile()->update([
            'is_available' => $validated['is_available']
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Availability updated.',
            'data' => $user->staffProfile->fresh()
        ]);
    }

    /**
     * Upload Profile or Cover Picture.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:avatar,cover',
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $user = $request->user();
        $file = $request->file('file');
        
        $path = $file->store('users/' . $user->id, 'public');
        $url = config('app.url') . Storage::url($path);

        if ($request->type === 'avatar') {
            $user->update(['profile_picture' => $url]);
        } else {
            $user->update(['cover_photo' => $url]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully.',
            'data' => $user->fresh()
        ]);
    }
}
