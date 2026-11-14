<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\Store;
use App\Models\User;

/**
 * Builds a free-fix order: a P0 order linked to a completed one (follow_up_of_id) that starts in Confirmed with
 * every pre-production approval already stamped. Shared by the owner's "Free fix" button (OrderFollowUpController)
 * and by an accepted warranty claim (OrderClaimController). The caller owns the transaction, the audit log
 * and the notifications.
 */
final class FreeFixOrder
{
    /** Plain details copied from the original into custom_order_data (never the team list). */
    public const GARMENT_KEYS = [
        'garment_type', 'garment_component', 'fabric_preference', 'colorway_notes', 'neckline_style',
        'shorts_cut', 'standard_size', 'order_purpose',
    ];

    /**
     * @param  array  $customData  Extra custom_order_data keys (e.g. warranty_claim_id).
     * @param  array  $extra  Optional: due_date (Y-m-d), assigned_staff_id, reference_images.
     */
    public static function create(Store $store, JobOrder $original, User $actor, string $note, int $pieces, array $customData = [], array $extra = []): JobOrder
    {
        $data = ['free_fix' => true];
        foreach (self::GARMENT_KEYS as $key) {
            if (isset($original->custom_order_data[$key])) {
                $data[$key] = $original->custom_order_data[$key];
            }
        }
        $data = array_merge($data, $customData);
        $dueDate = $extra['due_date'] ?? null;
        $orderNumber = OrderNumber::next($store->id);

        $attributes = [
            'order_number' => $orderNumber,
            'tracking_code' => TrackingCode::make($store),
            'intake_channel' => $original->intake_channel ?? 'walk_in',
            'fulfillment_type' => $original->fulfillment_type ?? 'pickup',
            'store_branch_id' => $original->store_branch_id,
            'customer_id' => $original->customer_id,
            'service_id' => $original->service_id,
            'catalog_item_id' => $original->catalog_item_id,
            'measurement_id' => $original->measurement_id,
            'material_source' => $original->material_source,
            'garment_category' => $original->garment_category,
            'measurement_requirement' => $original->measurement_requirement,
            'fitting_requirement' => $original->fitting_requirement,
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
            'follow_up_of_id' => $original->id,
            'consultation_notes' => $note,
            'notes' => $note,
            'shop_accepted_at' => now(),
        ];
        // due_date, assigned_staff_id and reference_images are all fillable on JobOrder.
        foreach (['due_date', 'assigned_staff_id', 'reference_images'] as $key) {
            if (! empty($extra[$key])) {
                $attributes[$key] = $extra[$key];
            }
        }

        // Rules the original did not store are left for the service's own defaults.
        $fix = $store->jobOrders()->create(array_filter(
            $attributes,
            fn ($value, $key) => $value !== null || ! in_array($key, ['measurement_requirement', 'fitting_requirement'], true),
            ARRAY_FILTER_USE_BOTH
        ));

        // A final quotation of P0 (the same writer the quotation window uses), then the approvals a normal
        // order collects one by one: every check Request 1-6 and "Start production" look at is stamped here.
        OrderQuotation::apply($fix, OrderQuotation::normalize(['base_price' => 0, 'notes' => $note]), 'final', $dueDate, $actor->id, [
            'payment_policy' => 'none', 'payment_policy_percent' => 0,
        ]);
        $fix->refresh()->forceFill([
            'payment_status' => 'paid',
            'shop_accepted_by' => $actor->id,
            'specs_approved_at' => now(), 'specs_approved_by' => $actor->id,
            'measurements_verified_at' => now(), 'measurements_verified_by' => $actor->id,
            'customer_items_assessment' => $original->customer_items_assessment,
            'customer_approved_at' => now(), 'customer_approval_channel' => 'in_person',
            'customer_approval_snapshot' => [
                'total' => 0.0, 'due_date' => $dueDate, 'required_deposit' => 0.0,
                'payment_policy' => 'none', 'payment_policy_percent' => 0, 'policy' => $store->cancellationPolicyText(),
            ],
            'customer_decision' => 'accepted', 'customer_decision_note' => null, 'customer_decided_at' => now(),
            'payment_terms_locked_at' => now(),
            'confirmed_at' => now(),
            'production_authorized_at' => now(), 'production_authorized_by' => $actor->id,
        ])->save();

        return $fix;
    }
}
