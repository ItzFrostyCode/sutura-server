<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => SubscriptionPlan::all()
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'description' => 'nullable|string',
            'price_monthly' => 'required|numeric|min:0',
            'price_yearly' => 'required|numeric|min:0',
            'max_staff' => 'required|integer',
            'max_services' => 'required|integer',
            'max_appointments_per_month' => 'required|integer',
            'features' => 'nullable|array',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $plan = SubscriptionPlan::create($validated);

        return response()->json([
            'success' => true,
            'data' => $plan
        ], 201);
    }
}
