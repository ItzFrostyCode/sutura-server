<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ChecksOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Store;
use App\Notifications\JobStatusUpdatedNotification;
use App\Notifications\NewJobOrderNotification;
use App\Support\OrderNumber;
use App\Support\OrderQuotation;
use App\Support\SafeNotify;
use App\Support\TrackingCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A free fix: the shop's mistake on a completed order. It becomes a new order linked to the first
 * (follow_up_of_id) that starts in Confirmed at P0: no price talk, nothing to approve or pay.
 * Owner / branch manager only. A customer's request or a repeat order goes through Request as usual.
 */
class OrderFollowUpController extends Controller
{
    use ChecksOrderAccess;

    /** Plain details copied from the original into custom_order_data (never the team list). */
    private const GARMENT_KEYS = [
        'garment_type', 'garment_component', 'fabric_preference', 'colorway_notes', 'neckline_style',
        'shorts_cut', 'standard_size', 'order_purpose',
    ];

    public function freeFix(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        if (! $this->isMoneyRole($request)) {
            return response()->json(['success' => false, 'message' => 'Only the owner or a branch manager can create a free fix.'], 403);
        }
        if ($jobOrder->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'A free fix can only follow a completed order.'], 422);
        }
        $v = $request->validate([
            'description' => ['required', 'string', 'max:500'],
            'pieces' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $description = trim($v['description']);
        if ($description === '') {
            return response()->json(['success' => false, 'message' => 'Tell us what needs fixing.', 'errors' => ['description' => ['Required.']]], 422);
        }
        $pieces = (int) ($v['pieces'] ?? $jobOrder->garmentCount());
        $user = $request->user();
        $note = "Free fix for {$jobOrder->order_number}: {$description}";

        $fix = DB::transaction(function () use ($request, $store, $jobOrder, $user, $pieces, $note) {
            $data = ['free_fix' => true];
            foreach (self::GARMENT_KEYS as $key) {
                if (isset($jobOrder->custom_order_data[$key])) {
                    $data[$key] = $jobOrder->custom_order_data[$key];
                }
            }
            $orderNumber = OrderNumber::next($store->id);

            $fix = $store->jobOrders()->create(array_filter([
                'order_number' => $orderNumber,
                'tracking_code' => TrackingCode::make($store),
                'intake_channel' => $jobOrder->intake_channel ?? 'walk_in',
                'fulfillment_type' => $jobOrder->fulfillment_type ?? 'pickup',
                'store_branch_id' => $jobOrder->store_branch_id,
                'customer_id' => $jobOrder->customer_id,
                'service_id' => $jobOrder->service_id,
                'catalog_item_id' => $jobOrder->catalog_item_id,
                'measurement_id' => $jobOrder->measurement_id,
                'material_source' => $jobOrder->material_source,
                'garment_category' => $jobOrder->garment_category,
                'measurement_requirement' => $jobOrder->measurement_requirement,
                'fitting_requirement' => $jobOrder->fitting_requirement,
                'custom_order_data' => $data,
                'quantity' => $pieces,
                'total_amount' => 0,
                'balance' => 0,
                'discount_amount' => 0,
                'payment_status' => 'paid',
                'payment_policy' => 'none',
                'payment_policy_percent' => 0,
                'status' => 'confirmed',
                'uses_confirmation_flow' => true,
                'follow_up_of_id' => $jobOrder->id,
                'consultation_notes' => $note,
                'notes' => $note,
                'shop_accepted_at' => now(),
            // Rules the original did not store are left for the service's own defaults.
            ], fn ($value, $key) => $value !== null || ! in_array($key, ['measurement_requirement', 'fitting_requirement'], true), ARRAY_FILTER_USE_BOTH));

            // A final quotation of P0 (the same writer the quotation window uses), then the approvals a normal
            // order collects one by one: every check Request 1-6 and "Start production" look at is stamped here.
            OrderQuotation::apply($fix, OrderQuotation::normalize(['base_price' => 0, 'notes' => $note]), 'final', null, $user->id, [
                'payment_policy' => 'none', 'payment_policy_percent' => 0,
            ]);
            $fix->refresh()->forceFill([
                'payment_status' => 'paid',
                'shop_accepted_by' => $user->id,
                'specs_approved_at' => now(), 'specs_approved_by' => $user->id,
                'measurements_verified_at' => now(), 'measurements_verified_by' => $user->id,
                'customer_items_assessment' => $jobOrder->customer_items_assessment,
                'customer_approved_at' => now(), 'customer_approval_channel' => 'in_person',
                'customer_approval_snapshot' => [
                    'total' => 0.0, 'due_date' => null, 'required_deposit' => 0.0,
                    'payment_policy' => 'none', 'payment_policy_percent' => 0, 'policy' => $store->cancellationPolicyText(),
                ],
                'customer_decision' => 'accepted', 'customer_decision_note' => null, 'customer_decided_at' => now(),
                'payment_terms_locked_at' => now(),
                'confirmed_at' => now(),
                'production_authorized_at' => now(), 'production_authorized_by' => $user->id,
            ])->save();

            $this->logOrderAction($request, $store, $fix, 'job_order_free_fix', [
                'order_number' => $fix->order_number,
                'fix_of' => $jobOrder->order_number,
                'fix_of_id' => $jobOrder->id,
                'pieces' => $pieces,
                'description' => $note,
            ]);

            return $fix;
        });

        $fix->load(['customer:id,name', 'service']);
        SafeNotify::send($fix->customer, new JobStatusUpdatedNotification($fix, 'confirmed', $user));
        SafeNotify::send($store->owner, new NewJobOrderNotification($fix));

        return response()->json(['success' => true, 'message' => 'Free fix order created.', 'data' => $fix], 201);
    }
}
