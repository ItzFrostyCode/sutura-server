<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Notifications\PaymentReceivedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "I have paid": a customer submits proof of a payment they made OUTSIDE SUTURA (GCash, Maya, bank…).
 * It sits as pending verification — the balance only moves once the shop verifies it. SUTURA never
 * handles money or payment credentials.
 */
class CustomerPaymentController extends Controller
{
    public function store(Request $request, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->customer_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
            'reference' => ['nullable', 'string', 'max:255'],
            'receipt_path' => ['required', 'string', 'max:2048'],
            'type' => ['sometimes', 'nullable', 'in:'.implode(',', Payment::TYPES)],
        ]);

        $method = PaymentMethod::where('id', $validated['payment_method_id'])
            ->where('store_id', $jobOrder->store_id)->where('is_active', true)->first();
        if (! $method) {
            return response()->json(['success' => false, 'message' => 'That payment method is not available.'], 422);
        }
        // Branch-specific accounts only apply to that branch's orders.
        if ($method->store_branch_id && (int) $method->store_branch_id !== (int) $jobOrder->store_branch_id) {
            return response()->json(['success' => false, 'message' => 'That payment method belongs to another branch.'], 422);
        }

        $amount = (float) $validated['amount'];

        try {
            $payment = DB::transaction(function () use ($jobOrder, $validated, $method, $amount, $request) {
                $locked = JobOrder::where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();
                if (in_array($locked->status, ['cancelled', 'rejected'], true)) {
                    throw new \RuntimeException('This order is no longer active.');
                }
                $pending = (float) $locked->payments()->whereNull('verified_at')->whereNull('rejected_at')->sum('amount');
                $outstanding = round((float) $locked->balance - $pending, 2);
                if ($outstanding <= 0) {
                    throw new \RuntimeException('Nothing left to pay — your earlier payment is still being verified.');
                }
                if ($amount > $outstanding + 0.005) {
                    throw new \RuntimeException('That is more than what is left to pay (₱'.number_format($outstanding, 2).').');
                }

                return $locked->payments()->create([
                    'amount' => $amount,
                    'payment_method' => $method->kind === 'maya' ? 'paymaya' : $method->kind,
                    'source' => 'online',
                    'type' => $validated['type'] ?? Payment::inferType($locked, $amount),
                    'payment_method_id' => $method->id,
                    'reference' => $validated['reference'] ?? null,
                    'recorded_by' => $request->user()->id,
                    'receipt_path' => $validated['receipt_path'],
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $jobOrder->store?->owner?->notify(new PaymentReceivedNotification($jobOrder, $amount, true));

        return response()->json([
            'success' => true,
            'message' => 'Payment submitted — the shop will verify it and update your balance.',
            'data' => $payment,
        ], 201);
    }
}
