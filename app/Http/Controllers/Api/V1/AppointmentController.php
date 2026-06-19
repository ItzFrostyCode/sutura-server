<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreAppointmentRequest;
use App\Http\Requests\Shop\UpdateAppointmentRequest;
use App\Models\Shop;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index(Request $request, Shop $shop): JsonResponse
    {
        $query = $shop->appointments()->with(['customer:id,name', 'service:id,name']);

        if ($request->user()->hasRole('branch_manager')) {
            $branchId = $request->user()->staffProfile->shop_branch_id ?? null;
            if ($branchId) {
                $query->where('shop_branch_id', $branchId);
            }
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date')) {
            $query->whereDate('scheduled_at', $request->date);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    public function store(StoreAppointmentRequest $request, Shop $shop): JsonResponse
    {
        $appointment = $shop->appointments()->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $appointment->load(['customer:id,name', 'service'])
        ], 201);
    }

    public function update(UpdateAppointmentRequest $request, Shop $shop, Appointment $appointment): JsonResponse
    {
        if ($appointment->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $appointment->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $appointment
        ]);
    }

    public function destroy(Shop $shop, Appointment $appointment): JsonResponse
    {
        if ($appointment->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $appointment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Appointment cancelled.'
        ]);
    }
}
