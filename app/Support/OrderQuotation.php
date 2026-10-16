<?php

namespace App\Support;

use App\Models\JobOrder;
use Illuminate\Support\Facades\DB;

/**
 * The quotation on an order: calculated service price, quantity pricing, additional charges,
 * less customer-provided items -> total. Saving one writes the total onto the order and moves
 * the balance by the same amount, so payments already made stay correct.
 */
final class OrderQuotation
{
    /** @param array<string,mixed> $in */
    public static function normalize(array $in): array
    {
        $rows = fn ($list) => collect($list ?? [])
            ->filter(fn ($r) => trim((string) ($r['label'] ?? '')) !== '')
            ->map(fn ($r) => ['label' => mb_substr(trim($r['label']), 0, 80), 'amount' => round(max(0, (float) ($r['amount'] ?? 0)), 2)])
            ->values()->all();

        $base = round(max(0, (float) ($in['base_price'] ?? 0)), 2);
        $qty = round((float) ($in['quantity_adjustment'] ?? 0), 2);
        $add = $rows($in['additional_charges'] ?? []);
        $ded = $rows($in['customer_provided_deduction'] ?? []);

        return [
            'base_price' => $base,
            'quantity_adjustment' => $qty,
            'additional_charges' => $add,
            'customer_provided_deduction' => $ded,
            'notes' => isset($in['notes']) ? mb_substr(trim((string) $in['notes']), 0, 500) : null,
            'total' => max(0.0, round($base + $qty + array_sum(array_column($add, 'amount')) - array_sum(array_column($ded, 'amount')), 2)),
        ];
    }

    /**
     * A quotation is only FINAL once nothing that could change the price is still open.
     * Anything open leaves it an estimate.
     *
     * @return list<array{key:string,message:string}>
     */
    public static function finalizeBlockers(JobOrder $job, array $q, ?string $dueDate): array
    {
        $out = [];
        if ($q['base_price'] <= 0) {
            $out[] = ['key' => 'price', 'message' => 'Enter the calculated service price.'];
        }
        if (! $dueDate) {
            $out[] = ['key' => 'date', 'message' => 'Set the agreed completion date.'];
        }
        if (! $job->specs_approved_at) {
            $out[] = ['key' => 'specs', 'message' => 'The design and specifications are not approved yet.'];
        }
        if (OrderReadiness::measurementsNeeded($job) && ! $job->measurements_verified_at) {
            $out[] = ['key' => 'measurements', 'message' => 'The measurements are not verified yet.'];
        }
        if (! OrderReadiness::customerItemsOk($job)) {
            $out[] = ['key' => 'customer_items', 'message' => 'The customer\'s own materials have not been assessed as suitable yet.'];
        }

        return $out;
    }

    /**
     * Write the quotation onto the order and keep a numbered copy of it. $options may carry the proposed
     * payment terms (payment_policy, payment_policy_percent), valid_until and a reason. A new total, date
     * or payment terms after the customer approved voids that approval (they must accept again).
     *
     * @param  array{payment_policy?:?string,payment_policy_percent?:?int,valid_until?:?string,reason?:?string}  $options
     * @return array{approval_cleared:bool,version:int,sent:bool}
     *
     * @throws \RuntimeException when the new total would be lower than what is already paid
     */
    public static function apply(JobOrder $job, array $q, string $status, ?string $dueDate, ?int $actorId, array $options = []): array
    {
        return DB::transaction(function () use ($job, $q, $status, $dueDate, $actorId, $options) {
            $locked = JobOrder::where('id', $job->id)->lockForUpdate()->firstOrFail();
            $paid = OrderReadiness::paidSoFar($locked);
            $discount = (float) $locked->discount_amount;

            if ($q['total'] - $discount < $paid - 0.005) {
                throw new \RuntimeException('The total cannot be lower than the ₱'.number_format($paid, 2).' already paid on this order.');
            }
            $balance = round($q['total'] - $discount - $paid, 2);

            $policy = $options['payment_policy'] ?? $locked->payment_policy ?? 'deposit';
            $percent = (int) ($options['payment_policy_percent'] ?? $locked->payment_policy_percent ?? 50);

            $snap = $locked->customer_approval_snapshot;
            $termsChanged = is_array($snap) && array_key_exists('payment_policy', $snap)
                && (($snap['payment_policy'] ?? null) !== $policy || (int) ($snap['payment_policy_percent'] ?? 0) !== $percent);
            $approvalStale = $locked->customer_approved_at !== null
                && ((float) ($snap['total'] ?? -1) !== (float) $q['total'] || ($snap['due_date'] ?? null) !== $dueDate || $termsChanged);

            $locked->forceFill([
                'quotation' => $q + ['saved_by' => $actorId, 'saved_at' => now()->toIso8601String()],
                'quotation_status' => $status,
                'total_amount' => $q['total'],
                'balance' => $balance,
                'payment_status' => $balance <= 0 && $q['total'] > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                'due_date' => $dueDate,
                'payment_policy' => $policy,
                'payment_policy_percent' => $percent,
                'quotation_valid_until' => $options['valid_until'] ?? $locked->quotation_valid_until,
            ]);
            if ($status === 'final') {
                // Sending a final quotation answers any "please change it" request.
                $locked->forceFill(['quotation_sent_at' => now(), 'customer_decision' => null, 'customer_decision_note' => null, 'customer_decided_at' => null]);
            }
            if ($approvalStale) {
                $locked->forceFill([
                    'customer_approved_at' => null, 'customer_approval_channel' => null, 'customer_approval_snapshot' => null,
                    'customer_decision' => null, 'customer_decided_at' => null, 'payment_terms_locked_at' => null,
                ]);
            }
            $locked->save();

            $version = (int) $locked->quotationVersions()->max('version') + 1;
            $locked->quotationVersions()->create([
                'version' => $version, 'status' => $status, 'total' => $q['total'], 'due_date' => $dueDate,
                'valid_until' => $locked->quotation_valid_until?->toDateString(),
                'payment_policy' => $policy, 'payment_policy_percent' => $percent,
                'snapshot' => $q, 'reason' => $options['reason'] ?? null, 'created_by' => $actorId,
            ]);

            return ['approval_cleared' => $approvalStale, 'version' => $version, 'sent' => $status === 'final'];
        });
    }
}
