<?php

namespace App\Support;

use App\Models\JobOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Batches: a group order whose people pay at different times is split into linked orders (the same design, a
 * different payer). Each part is a normal order with its own total, deposit, due date, handover and tracking code.
 * The overall figures are always worked out from the parts, never stored.
 */
class OrderBatches
{
    public const SPLIT_STATUSES = ['pending', 'confirmed', 'awaiting_materials'];

    /** How many people an order is for (its team list). */
    public static function people(JobOrder $job): int
    {
        $roster = $job->custom_order_data['team_roster'] ?? [];

        return is_array($roster) ? count($roster) : 0;
    }

    public static function canSplit(JobOrder $job): bool
    {
        // A set of different services is not split by payer (each service is already its own order).
        if ($job->group_kind === 'set') {
            return false;
        }

        return in_array($job->status, self::SPLIT_STATUSES, true) && self::people($job) >= 2;
    }

    /**
     * The group an order belongs to, with the overall figures. Null when the order was never split.
     *
     * @param  bool  $money  include amounts (owner / manager / the customer, never plain staff)
     * @param  bool  $onlyThis  list only this order (a guest with a tracking code sees nothing of the others)
     */
    public static function summary(JobOrder $job, bool $money, bool $onlyThis = false): ?array
    {
        $rootId = $job->batchRootId();
        $orders = JobOrder::where('customer_id', $job->customer_id)
            ->where(fn ($q) => $q->where('id', $rootId)->orWhere('parent_order_id', $rootId))
            ->with('service:id,name')->orderBy('batch_no')->orderBy('id')->get();
        if ($orders->count() < 2) {
            return null;
        }
        $paidOf = fn (JobOrder $o) => OrderReadiness::paidSoFar($o);
        $row = fn (JobOrder $o) => [
            'id' => $o->id, 'order_number' => $o->order_number, 'batch_no' => $o->batch_no ?? 1, 'status' => $o->status,
            'paid_by_name' => $o->paid_by_name, 'people' => max(self::people($o), 1), 'service_name' => $o->service?->name,
        ] + ($money ? [
            'total_amount' => (float) $o->total_amount, 'discount_amount' => (float) $o->discount_amount,
            'paid' => $paidOf($o), 'balance' => (float) $o->balance,
        ] : []);

        $open = $orders->reject(fn (JobOrder $o) => in_array($o->status, ['cancelled', 'rejected'], true));
        $totals = null;
        if ($money && ! $onlyThis) {
            $total = round($open->sum(fn ($o) => (float) $o->total_amount), 2);
            $discount = round($open->sum(fn ($o) => (float) $o->discount_amount), 2);
            $totals = [
                'total' => $total, 'discount' => $discount, 'grand_total' => round($total - $discount, 2),
                'paid' => round($open->sum($paidOf), 2), 'balance' => round($open->sum(fn ($o) => (float) $o->balance), 2),
            ];
        }

        return [
            'kind' => $job->group_kind ?? 'batch',
            'root_id' => $rootId,
            'count' => $orders->count(),
            'this_batch' => $job->batch_no ?? 1,
            'orders' => ($onlyThis ? $orders->where('id', $job->id) : $orders)->map($row)->values()->all(),
            'totals' => $totals,
        ];
    }

    /**
     * Moves chosen people out of $parent into new linked orders.
     *
     * @param  list<array{people:list<int>,paid_by_name?:?string,total_amount?:?float,discount_amount?:?float}>  $groups  people = positions in the team list (0-based)
     * @return array{ok:bool,message?:string,children?:list<JobOrder>}
     */
    public static function split(Store $store, JobOrder $parent, array $groups, User $actor, ?string $ip = null): array
    {
        $data = (array) $parent->custom_order_data;
        $roster = array_values((array) ($data['team_roster'] ?? []));
        $n = count($roster);
        if (! self::canSplit($parent)) {
            return ['ok' => false, 'message' => 'Only a group order that has not started production can be split.'];
        }

        $seen = [];
        foreach ($groups as $g) {
            if (empty($g['people'])) {
                return ['ok' => false, 'message' => 'Every batch needs at least one person.'];
            }
            foreach ($g['people'] as $p) {
                if (! is_int($p) || $p < 0 || $p >= $n || isset($seen[$p])) {
                    return ['ok' => false, 'message' => 'A person is listed twice or does not exist.'];
                }
                $seen[$p] = true;
            }
        }
        if (count($seen) >= $n) {
            return ['ok' => false, 'message' => 'At least one person has to stay in this order.'];
        }

        $total = (float) $parent->total_amount;
        $discount = (float) $parent->discount_amount;
        $per = $n > 0 ? $total / $n : 0.0;
        $parts = [];
        foreach ($groups as $g) {
            $k = count($g['people']);
            $partTotal = isset($g['total_amount']) ? round((float) $g['total_amount'], 2) : round($per * $k, 2);
            $partDiscount = isset($g['discount_amount']) ? round((float) $g['discount_amount'], 2) : ($total > 0 ? round($discount * ($partTotal / $total), 2) : 0.0);
            if ($partTotal < 0 || $partDiscount < 0 || $partDiscount > $partTotal) {
                return ['ok' => false, 'message' => 'A batch has a discount larger than its total.'];
            }
            $parts[] = ['g' => $g, 'total' => $partTotal, 'discount' => $partDiscount];
        }
        $newTotal = round($total - array_sum(array_column($parts, 'total')), 2);
        $newDiscount = round($discount - array_sum(array_column($parts, 'discount')), 2);
        $paid = OrderReadiness::paidSoFar($parent);
        $newBalance = round($newTotal - $newDiscount - $paid, 2);
        if ($newTotal < 0 || $newDiscount < 0 || $newDiscount > $newTotal || $newBalance < -0.005) {
            return ['ok' => false, 'message' => 'What this order already received is more than what would be left for the people staying. Lower the amounts moved out.'];
        }

        $children = [];
        JobOrder::$quietCreate = true;
        try {
            DB::transaction(function () use ($store, $parent, $parts, $roster, $data, $total, $newTotal, $newDiscount, $newBalance, $paid, $actor, $ip, &$children) {
                $rootId = $parent->batchRootId();
                $root = $rootId === $parent->id ? $parent : JobOrder::findOrFail($rootId);
                $nextNo = (int) JobOrder::where('id', $rootId)->orWhere('parent_order_id', $rootId)->max('batch_no');
                $nextNo = max($nextNo, 1);
                $specRows = array_values((array) ($data['spec_table']['rows'] ?? []));
                $subset = fn (array $rows, array $idx) => array_values(array_intersect_key($rows, array_flip($idx)));

                foreach ($parts as $part) {
                    $idx = $part['g']['people'];
                    $nextNo++;
                    $child = $parent->replicate();
                    $childData = array_merge($data, [
                        'team_roster' => $subset($roster, $idx),
                        'people_count' => count($idx),
                    ]);
                    if (count($specRows) === count($roster)) {
                        $childData['spec_table'] = array_merge((array) $data['spec_table'], ['rows' => $subset($specRows, $idx)]);
                    }
                    $child->forceFill([
                        'order_number' => $root->order_number.'-'.chr(64 + min($nextNo, 26)),
                        'tracking_code' => TrackingCode::make($store),
                        'parent_order_id' => $rootId,
                        'batch_no' => $nextNo,
                        'group_kind' => 'batch',
                        'paid_by_name' => $part['g']['paid_by_name'] ?? null,
                        'appointment_id' => null,
                        'total_amount' => $part['total'],
                        'discount_amount' => $part['discount'],
                        'balance' => round($part['total'] - $part['discount'], 2),
                        'payment_status' => 'unpaid',
                        'custom_order_data' => $childData,
                        'quotation' => self::scaleQuotation($parent->quotation, $total > 0 ? $part['total'] / $total : 0, $part['total']),
                        'released_with_balance_at' => null,
                        'balance_due_by' => null,
                    ])->save();
                    foreach ($parent->assignees as $a) {
                        $child->assignees()->create(['user_id' => $a->user_id, 'role' => $a->role, 'note' => $a->note, 'assigned_by' => $a->assigned_by]);
                    }
                    $children[] = $child;
                    $store->auditLogs()->create(['user_id' => $actor->id, 'action' => 'order_split_child', 'model_type' => JobOrder::class, 'model_id' => $child->id,
                        'payload' => ['from_order' => $parent->id, 'people' => count($idx), 'total' => $part['total'], 'discount' => $part['discount']], 'ip_address' => $ip]);
                }

                $moved = array_merge(...array_map(fn ($p) => $p['g']['people'], $parts));
                $stay = array_values(array_diff(array_keys($roster), $moved));
                $parentData = array_merge($data, ['team_roster' => $subset($roster, $stay), 'people_count' => count($stay)]);
                if (count($specRows) === count($roster)) {
                    $parentData['spec_table'] = array_merge((array) $data['spec_table'], ['rows' => $subset($specRows, $stay)]);
                }
                $parent->forceFill([
                    'total_amount' => $newTotal, 'discount_amount' => $newDiscount, 'balance' => max(0, $newBalance),
                    'payment_status' => $newBalance <= 0.005 && $newTotal > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                    'custom_order_data' => $parentData,
                    'quotation' => self::scaleQuotation($parent->quotation, $total > 0 ? $newTotal / $total : 0, $newTotal),
                    'batch_no' => $parent->batch_no ?? 1,
                    'group_kind' => 'batch',
                ])->save();
                if ($root->id !== $parent->id && ! $root->batch_no) {
                    $root->forceFill(['batch_no' => 1])->save();
                }
                $store->auditLogs()->create(['user_id' => $actor->id, 'action' => 'order_split', 'model_type' => JobOrder::class, 'model_id' => $parent->id,
                    'payload' => ['children' => array_map(fn ($c) => $c->id, $children), 'people_left' => count($stay), 'total_left' => $newTotal], 'ip_address' => $ip]);
            });
        } finally {
            JobOrder::$quietCreate = false;
        }

        return ['ok' => true, 'children' => $children];
    }

    /**
     * Other services the customer asked for at the same visit: each becomes its own order (own price, deposit, due date,
     * handover and tracking code), linked to the main one. They share the customer, the consultation and the design notes.
     *
     * @param  list<array{service_id:int,catalog_item_id?:?int,quantity?:?int,total_amount?:?float,notes?:?string}>  $extras
     * @return list<JobOrder>
     */
    public static function attachExtraServices(Store $store, JobOrder $main, array $extras): array
    {
        $made = [];
        $main = $main->fresh() ?? $main; // the saved row, with the database's own defaults
        JobOrder::$quietCreate = true;
        try {
            DB::transaction(function () use ($store, $main, $extras, &$made) {
                $main->forceFill(['group_kind' => 'set', 'batch_no' => 1])->save();
                $no = 1;
                foreach ($extras as $x) {
                    $no++;
                    $service = \App\Models\Service::where('store_id', $store->id)->find($x['service_id']);
                    $qty = max(1, (int) ($x['quantity'] ?? 1));
                    $total = isset($x['total_amount']) ? round((float) $x['total_amount'], 2) : round((float) ($service?->base_price ?? 0) * $qty, 2);
                    $made[] = $store->jobOrders()->create([
                        'order_number' => $main->order_number.'-'.chr(64 + min($no, 26)),
                        'tracking_code' => TrackingCode::make($store),
                        'intake_channel' => $main->intake_channel ?? 'walk_in', 'fulfillment_type' => $main->fulfillment_type ?? 'pickup',
                        'store_branch_id' => $main->store_branch_id, 'customer_id' => $main->customer_id,
                        'service_id' => $x['service_id'], 'catalog_item_id' => $x['catalog_item_id'] ?? null,
                        'quantity' => $qty, 'total_amount' => $total, 'balance' => $total, 'payment_status' => 'unpaid',
                        'status' => 'pending', 'uses_confirmation_flow' => $main->uses_confirmation_flow,
                        'due_date' => $main->due_date, 'notes' => $x['notes'] ?? null, 'material_source' => $main->material_source ?? 'store_supplied',
                        'design_source' => $main->design_source, 'design_link' => $main->design_link, 'consultation_notes' => $main->consultation_notes,
                        'parent_order_id' => $main->id, 'batch_no' => $no, 'group_kind' => 'set',
                    ]);
                }
            });
        } finally {
            JobOrder::$quietCreate = false;
        }

        return $made;
    }

    /** The quotation with every amount scaled to this part's share, so it still adds up to the part's total. */
    private static function scaleQuotation(?array $q, float $ratio, float $total): ?array
    {
        if (! is_array($q)) {
            return $q;
        }
        foreach (['base_price', 'quantity_adjustment'] as $k) {
            if (isset($q[$k])) {
                $q[$k] = round((float) $q[$k] * $ratio, 2);
            }
        }
        foreach (['additional_charges', 'customer_provided_deduction'] as $k) {
            if (! empty($q[$k]) && is_array($q[$k])) {
                $q[$k] = array_map(fn ($c) => is_array($c) && isset($c['amount']) ? array_merge($c, ['amount' => round((float) $c['amount'] * $ratio, 2)]) : $c, $q[$k]);
            }
        }
        $q['total'] = round($total, 2);

        return $q;
    }
}
