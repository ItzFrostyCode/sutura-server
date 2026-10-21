<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\User;

/**
 * Builds the "Order steps" payload the dashboard shows on a job: the set-up checklist (Flow 2) and the
 * delivery stages (Flow 3). Plain staff get the same steps but no prices, capacity or refund figures
 * (owner-only financials).
 */
final class OrderFlowPresenter
{
    private const STAGE_FOR_STATUS = [
        'qc_ironing' => 'quality_check', 'ready_for_pickup' => 'collection', 'ready_for_fitting' => 'fitting',
        'final_adjustments' => 'correction', 'balance_handover' => 'handover', 'completed' => 'completed',
    ];

    public static function payload(JobOrder $job, User $viewer): array
    {
        $job = $job->fresh(['store', 'assignees.user:id,name,profile_picture']);
        $money = $viewer->hasRole('store_owner') || $viewer->hasRole('branch_manager');
        $gated = OrderReadiness::gated($job);

        $base = [
            'uses_confirmation_flow' => $gated,
            'status' => $job->status,
            'permissions' => [
                'can_see_money' => $money,
                'can_edit_quotation' => $money && $gated && $job->status === 'pending',
                'can_assign' => $money && $gated,
                'can_confirm' => $money && $gated && $job->status === 'pending',
                'can_approve_change' => $money && $gated,
                'can_manage_refund' => $money && $job->status === 'cancelled',
                'can_accept_request' => $money && $gated && $job->status === 'pending' && ! $job->shop_accepted_at,
            ],
        ];
        if (! $gated) {
            return $base;
        }

        $started = $job->production_started_at !== null;
        $needMeasure = OrderReadiness::measurementsNeeded($job);
        $customerItems = OrderReadiness::customerItemsApplicable($job);
        $materialsReady = OrderReadiness::materialsReady($job);

        $deposit = [
            'met' => OrderReadiness::depositMet($job),
            'policy' => $job->payment_policy,
            'policy_percent' => $job->payment_policy_percent,
        ];
        if ($money) {
            $deposit += [
                'required' => OrderReadiness::requiredDeposit($job),
                'paid' => OrderReadiness::paidSoFar($job),
                'pending_verification' => OrderReadiness::pendingVerification($job),
            ];
        }

        $item = fn (string $key, string $label, bool $done, ?string $detail = null, bool $applicable = true) => [
            'key' => $key, 'label' => $label, 'done' => $done, 'detail' => $detail, 'applicable' => $applicable,
        ];

        // The order follows the workflow: specs, measurements, the customer's own materials, quotation,
        // approval, deposit, confirmed, materials ready, tailor, production started.
        $checklist = [
            $item('specs', 'Design & specifications approved', $job->specs_approved_at !== null),
            $item('measurements', 'Measurements verified', $job->measurements_verified_at !== null, null, $needMeasure),
            $item('customer_items', 'Customer\'s own materials assessed', OrderReadiness::customerItemsOk($job),
                $job->customer_items_assessment === 'not_suitable' ? 'Not suitable — talk to the customer' : null, $customerItems),
            $item('quotation', 'Final quotation', $job->quotation_status === 'final',
                $job->quotation_status === 'estimate' ? 'Estimate only' : ($job->quotation_status ? null : 'Not prepared yet')),
            $item('approval', 'Customer approved quotation & terms', $job->customer_approved_at !== null,
                $job->customer_approval_channel ? ($job->customer_approval_channel === 'online' ? 'Approved online' : 'Approved in person') : null),
            $item('deposit', 'Deposit verified', $deposit['met'],
                $money ? '₱'.number_format($deposit['paid'], 2).' of ₱'.number_format($deposit['required'], 2) : null),
            $item('confirmed', 'Order confirmed', $job->confirmed_at !== null),
            $item('materials', 'Materials ready', $materialsReady,
                $job->status === 'awaiting_materials' ? ($job->materials_note ?: 'Waiting for materials') : $job->materials_note),
            $item('tailor', 'Tailor assigned', $job->assignees->isNotEmpty(),
                $job->assignees->isNotEmpty() ? $job->assignees->map(fn ($a) => $a->user?->name)->filter()->implode(', ') : null),
            $item('started', 'Production started', $started),
        ];

        $out = $base + [
            'order' => [
                'id' => $job->id,
                'order_number' => $job->order_number,
                'due_date' => $job->due_date?->toDateString(),
                'measurement_requirement' => $job->measurement_requirement,
                'fitting_requirement' => $job->fitting_requirement,
                'material_source' => $job->material_source,
                'is_rush' => (bool) $job->is_rush,
                'rush_fee' => (float) $job->rush_fee,
                'quantity' => $job->quantity,
                'people_count' => $job->custom_order_data['people_count'] ?? null,
            ],
            'checklist' => $checklist,
            'pre_production' => OrderPreProduction::steps($job),
            'queue' => ['received_at' => $job->created_at, 'accepted_at' => $job->shop_accepted_at, 'authorized_at' => $job->production_authorized_at],
            'terms' => [
                'policy' => $job->payment_policy, 'percent' => $job->payment_policy_percent,
                'text' => OrderReadiness::termsText($job), 'locked' => $job->payment_terms_locked_at !== null,
            ],
            'decision' => [
                'value' => $job->customer_decision, 'note' => $job->customer_decision_note, 'at' => $job->customer_decided_at,
                'valid_until' => $job->quotation_valid_until?->toDateString(),
            ],
            'assignee_history' => $job->assigneeEvents()->with(['fromUser:id,name', 'toUser:id,name', 'byUser:id,name'])->get()->map(fn ($e) => [
                'action' => $e->action, 'from' => $e->fromUser?->name, 'to' => $e->toUser?->name, 'by' => $e->byUser?->name,
                'reason' => $e->reason, 'at' => $e->created_at,
            ])->values(),
            'blockers' => [
                'confirm' => OrderReadiness::confirmationBlockers($job),
                'start' => OrderReadiness::startBlockers($job),
            ],
            'quotation' => [
                'status' => $job->quotation_status,
                'approved_at' => $job->customer_approved_at,
                'approval_channel' => $job->customer_approval_channel,
                'confirmed_at' => $job->confirmed_at,
                'specs_approved_at' => $job->specs_approved_at,
                'measurements_verified_at' => $job->measurements_verified_at,
                'production_started_at' => $job->production_started_at,
            ],
            'deposit' => $deposit,
            'materials' => [
                'ready' => $materialsReady,
                'waiting' => $job->status === 'awaiting_materials',
                'note' => $job->materials_note,
                'customer_items' => ['applicable' => $customerItems, 'assessment' => $job->customer_items_assessment],
            ],
            'assignees' => $job->assignees->map(fn ($a) => [
                'user_id' => $a->user_id, 'name' => $a->user?->name, 'profile_picture' => $a->user?->profile_picture,
                'role' => $a->role, 'note' => $a->note,
            ])->values(),
            'delivery' => self::delivery($job, $money),
            'policy' => $job->store?->cancellationPolicyText(),
        ];

        if ($money) {
            $out['quotation']['data'] = $job->quotation;
            $out['quotation']['versions'] = $job->quotationVersions()->with('author:id,name')->get()->map(fn ($v) => [
                'version' => $v->version, 'status' => $v->status, 'total' => $v->total, 'due_date' => $v->due_date?->toDateString(),
                'payment_policy' => $v->payment_policy, 'payment_policy_percent' => $v->payment_policy_percent,
                'reason' => $v->reason, 'by' => $v->author?->name, 'at' => $v->created_at,
            ])->values();
            $out['quotation']['total'] = (float) $job->total_amount;
            $out['capacity'] = OrderCapacity::forJob($job);
            $out['refunds'] = $job->refunds()->get();
        }

        return $out;
    }

    /** Flow 3: where the order is between production and completion, what is open, and what was handed over. */
    private static function delivery(JobOrder $job, bool $money): array
    {
        $corrections = $job->corrections()->with('assignee:id,name')->get();
        $open = $corrections->filter->isBlocking();
        $handovers = $job->handovers()->with('recordedBy:id,name')->get();
        $given = (int) $handovers->sum('quantity');
        $fittingNeeded = OrderReadiness::fittingNeeded($job);

        $status = $job->status;
        $current = in_array($status, JobOrder::PRODUCTION_TASKS, true) ? 'in_production' : (self::STAGE_FOR_STATUS[$status] ?? null);
        $order = ['in_production' => 1, 'quality_check' => 2, 'collection' => 3, 'fitting' => 4, 'correction' => 5, 'handover' => 6, 'completed' => 7];
        $curOrd = $order[$current] ?? 0;
        $cameFromFitting = $current === 'correction' && $open->contains(fn ($c) => $c->kind === 'fitting_issue');

        $stage = function (string $key, string $label, bool $applicable = true) use ($order, $curOrd, $current, $status, $cameFromFitting) {
            $o = $order[$key];
            if ($status === 'completed') {
                $state = 'done';
            } elseif ($current === 'correction' && in_array($key, ['collection', 'fitting'], true)) {
                $state = $cameFromFitting ? 'done' : 'upcoming';
            } elseif ($current === 'correction' && in_array($key, ['in_production', 'quality_check'], true)) {
                $state = 'done';
            } elseif ($key === 'correction') {
                $state = $current === 'correction' ? 'current' : 'upcoming';
            } else {
                $state = $o < $curOrd ? 'done' : ($o === $curOrd ? 'current' : 'upcoming');
            }

            return ['key' => $key, 'label' => $label, 'state' => $state, 'applicable' => $applicable];
        };

        return [
            'current' => $current,
            'stages' => [
                $stage('in_production', 'In production'),
                $stage('quality_check', 'Quality check'),
                $stage('collection', 'Ready for final fitting / collection'),
                $stage('fitting', 'Customer fitting / inspection', $fittingNeeded),
                $stage('correction', 'Adjustment / correction', $current === 'correction' || $corrections->isNotEmpty()),
                $stage('handover', 'Balance payment & handover'),
                $stage('completed', 'Completed'),
            ],
            'qc_passed_at' => $job->qc_passed_at,
            'fitting_requirement' => $job->fitting_requirement,
            'fitting_passed_at' => $job->fitting_passed_at,
            'open_corrections' => $open->count(),
            'corrections' => $corrections->map(fn ($c) => [
                'id' => $c->id, 'kind' => $c->kind, 'description' => $c->description, 'status' => $c->status,
                'affected_quantity' => $c->affected_quantity, 'shop_fault' => $c->shop_fault,
                'target_date' => $c->target_date?->toDateString(),
                'assigned_user_id' => $c->assigned_user_id, 'assignee_name' => $c->assignee?->name,
                'additional_amount' => $money ? $c->additional_amount : null,
                'approved_at' => $c->approved_at, 'resolved_at' => $c->resolved_at,
            ])->values(),
            'handover' => [
                'quantity' => $job->garmentCount(),
                // A group order counts its people; older orders count pieces.
                'unit' => ! empty($job->custom_order_data['people_count']) ? 'people' : 'garments',
                'handed_over' => $given,
                'remaining' => max(0, $job->garmentCount() - $given),
                'records' => $handovers->map(fn ($h) => [
                    'id' => $h->id, 'quantity' => $h->quantity, 'received_by' => $h->received_by,
                    'note' => $h->note, 'handed_over_at' => $h->handed_over_at, 'recorded_by' => $h->recordedBy?->name,
                ])->values(),
            ],
            'balance_clear' => (float) $job->balance <= 0.005,
            // Garments went out before the balance was paid (released by the owner or a branch manager).
            'released_with_balance' => $job->released_with_balance_at !== null && (float) $job->balance > 0.005,
            'balance_due_by' => $job->balance_due_by?->toDateString(),
            'balance' => $money ? (float) $job->balance : null,
        ];
    }
}
