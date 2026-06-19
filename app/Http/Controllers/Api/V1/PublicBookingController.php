<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PublicBookingController extends Controller
{
    /**
     * Get the dynamic booking settings for a specific shop.
     */
    public function getSettings(Shop $shop): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'name' => $shop->name,
                'description' => $shop->description,
                'booking_policy' => $shop->booking_policy,
                'booking_questions' => $shop->booking_questions ?? [],
            ]
        ]);
    }

    /**
     * Submit a new public appointment booking.
     */
    public function submit(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'scheduled_at' => 'required|date|after:today',
            'answers' => 'nullable|array'
        ]);

        // Find or create customer
        $customer = User::where('email', $validated['email'])->first();

        if (!$customer) {
            $customer = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => Hash::make(Str::random(16)), // auto-generated password
            ]);
            
            // Assign customer role
            $customerRole = Role::where('name', 'customer')->first();
            if ($customerRole) {
                $customer->roles()->attach($customerRole);
            }
        }

        // Create appointment
        $appointment = $shop->appointments()->create([
            'customer_id' => $customer->id,
            'scheduled_at' => $validated['scheduled_at'],
            'answers' => $validated['answers'] ?? null,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Appointment booked successfully.',
            'data' => $appointment
        ], 201);
    }
}
