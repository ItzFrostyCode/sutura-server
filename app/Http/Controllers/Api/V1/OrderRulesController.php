<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Store;
use App\Support\OrderRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The service's measurement / fitting / payment rules are the order's starting values, not walls: the owner or a
 * branch manager can change them for one order (agreed with the customer), with a reason that goes in the audit log.
 * Only this order's own copy changes; the service and other orders are untouched.
 */
class OrderRulesController extends Controller
{
    private const FIELDS = ['measurement_requirement', 'fitting_requirement', 'payment_policy', 'payment_policy_percent'];

    public function update(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($jobOrder->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Job order not found'], 404);
        }
        $userBranch = $request->user()->hasRole('store_owner') ? null : ($request->user()->staffProfile->store_branch_id ?? null);
        if ($userBranch && $jobOrder->store_branch_id && (int) $userBranch !== (int) $jobOrder->store_branch_id) {
            return response()->json(['success' => false, 'message' => 'This job order belongs to a different branch.'], 403);
        }

        $v = $request->validate([
            'measurement_requirement' => ['sometimes', Rule::in(OrderRequirements::MEASUREMENT)],
            'fitting_requirement' => ['sometimes', Rule::in(OrderRequirements::FITTING)],
            'payment_policy' => ['sometimes', Rule::in(OrderRequirements::PAYMENT)],
            'payment_policy_percent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if (in_array($jobOrder->status, ['completed', 'cancelled', 'rejected'], true)) {
            return response()->json(['success' => false, 'message' => 'This order is closed; its rules can no longer change.'], 422);
        }

        $changes = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $v) && $v[$field] !== $jobOrder->{$field}) {
                $changes[$field] = ['from' => $jobOrder->{$field}, 'to' => $v[$field]];
            }
        }
        if (! $changes) {
            return response()->json(['success' => false, 'message' => 'Nothing to change.'], 422);
        }
        $paymentChanged = isset($changes['payment_policy']) || isset($changes['payment_policy_percent']);
        if ($paymentChanged && (in_array($jobOrder->status, JobOrder::STAGES_REQUIRING_DOWNPAYMENT, true))) {
            return response()->json(['success' => false, 'message' => 'The payment rule can only be changed before production starts.'], 422);
        }

        DB::transaction(function () use ($store, $jobOrder, $changes, $v, $request) {
            $before = ['payment_policy' => $jobOrder->payment_policy ?? 'deposit', 'payment_policy_percent' => $jobOrder->payment_policy_percent];
            $jobOrder->update(array_map(fn ($c) => $c['to'], $changes));
            $log = fn (string $action, array $payload) => $store->auditLogs()->create([
                'user_id' => $request->user()->id, 'action' => $action, 'model_type' => JobOrder::class,
                'model_id' => $jobOrder->id, 'payload' => $payload, 'ip_address' => $request->ip(),
            ]);
            $log('order_rules_changed', ['changes' => $changes, 'reason' => $v['reason']]);

            // Keep the deposit-waiver trail in step, so its "require it again" history stays true.
            if (isset($changes['payment_policy'])) {
                if ($jobOrder->payment_policy === 'none') {
                    $log('deposit_waived', ['reason' => $v['reason'], 'previous_policy' => $before['payment_policy'], 'previous_percent' => $before['payment_policy_percent']]);
                } elseif ($before['payment_policy'] === 'none') {
                    $log('deposit_waiver_removed', ['restored_policy' => $jobOrder->payment_policy, 'restored_percent' => $jobOrder->payment_policy_percent]);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'The rules for this order were changed.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff', 'payments.recordedBy:id,name', 'staffStages']),
        ]);
    }
}
