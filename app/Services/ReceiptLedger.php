<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CatalogOrder;
use App\Models\Payment;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * One chronological list of every payment record a shop holds — job-order payments, appointment deposits
 * and catalog-order payments — each with its receipt screenshot (if any). Feeds the Statements screen,
 * the CSV / PDF statement and the ZIP of receipt images.
 */
class ReceiptLedger
{
    public const KINDS = ['job' => 'Order payment', 'appointment' => 'Appointment deposit', 'catalog' => 'Catalog order'];

    private const METHOD_LABELS = ['cash' => 'Cash', 'gcash' => 'GCash', 'paymaya' => 'Maya', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'];

    /**
     * @param  array<int,string>  $kinds  subset of job|appointment|catalog
     * @param  array<int,string>|null  $keys  e.g. ["job:12","catalog:3"] — restrict to exactly these records
     */
    public static function entries(Store $store, ?string $from = null, ?string $to = null, ?int $branchId = null, array $kinds = ['job', 'appointment', 'catalog'], ?array $keys = null): Collection
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : null;
        $end = $to ? Carbon::parse($to)->endOfDay() : null;
        $within = fn ($q, string $col = 'created_at') => $q->when($start, fn ($qq) => $qq->where($col, '>=', $start))->when($end, fn ($qq) => $qq->where($col, '<=', $end));
        $rows = collect();

        if (in_array('job', $kinds, true)) {
            $q = Payment::query()->with(['jobOrder:id,order_number,customer_id,store_branch_id', 'jobOrder.customer:id,name', 'jobOrder.branch:id,name', 'recordedBy:id,name'])
                ->whereHas('jobOrder', fn ($j) => $j->withTrashed()->where('store_id', $store->id)->when($branchId, fn ($jj) => $jj->where('store_branch_id', $branchId)));
            $within($q)->get()->each(function (Payment $p) use ($rows) {
                $rows->push([
                    'key' => 'job:'.$p->id, 'kind' => 'job', 'id' => $p->id,
                    'date' => $p->created_at,
                    'doc_no' => $p->jobOrder?->order_number,
                    'customer' => $p->jobOrder?->customer?->name,
                    'method' => self::METHOD_LABELS[$p->payment_method] ?? ucfirst((string) $p->payment_method),
                    'payment_type' => $p->type,
                    'source' => $p->source,
                    'amount' => (float) $p->amount,
                    'status' => $p->rejected_at ? 'rejected' : ($p->verified_at ? 'verified' : 'pending'),
                    'reference' => $p->reference,
                    'recorded_by' => $p->recordedBy?->name,
                    'branch' => $p->jobOrder?->branch?->name,
                    'receipt_path' => $p->receipt_path,
                ]);
            });
        }

        if (in_array('appointment', $kinds, true)) {
            $q = Appointment::query()->with(['customer:id,name', 'branch:id,name'])
                ->where('store_id', $store->id)->whereNotNull('payment_method')
                ->when($branchId, fn ($qq) => $qq->where('store_branch_id', $branchId));
            $within($q)->get()->each(function (Appointment $a) use ($rows) {
                $rows->push([
                    'key' => 'appointment:'.$a->id, 'kind' => 'appointment', 'id' => $a->id,
                    'date' => $a->created_at,
                    'doc_no' => 'APT-'.str_pad((string) $a->id, 4, '0', STR_PAD_LEFT),
                    'customer' => $a->customer?->name,
                    'method' => self::METHOD_LABELS[$a->payment_method] ?? ucfirst((string) $a->payment_method),
                    'payment_type' => 'deposit',
                    'source' => $a->intake_channel === 'online' ? 'online' : 'walk_in',
                    'amount' => null, // a booking deposit has no amount column — the proof is what is kept
                    'status' => (string) ($a->payment_status ?: 'pending'),
                    'reference' => $a->payment_reference,
                    'recorded_by' => null,
                    'branch' => $a->branch?->name,
                    'receipt_path' => $a->payment_receipt_path,
                ]);
            });
        }

        if (in_array('catalog', $kinds, true)) {
            $q = CatalogOrder::query()->with(['customer:id,name', 'branch:id,name'])
                ->where('store_id', $store->id)->whereNotNull('payment_method')
                ->when($branchId, fn ($qq) => $qq->where('store_branch_id', $branchId));
            $within($q)->get()->each(function (CatalogOrder $o) use ($rows) {
                $rows->push([
                    'key' => 'catalog:'.$o->id, 'kind' => 'catalog', 'id' => $o->id,
                    'date' => $o->created_at,
                    'doc_no' => $o->order_number,
                    'customer' => $o->customer?->name,
                    'method' => self::METHOD_LABELS[$o->payment_method] ?? ucfirst((string) $o->payment_method),
                    'payment_type' => null,
                    'source' => $o->intake_channel === 'online' ? 'online' : 'walk_in',
                    'amount' => (float) $o->total_amount,
                    'status' => (string) ($o->payment_status ?: 'pending'),
                    'reference' => $o->payment_reference,
                    'recorded_by' => null,
                    'branch' => $o->branch?->name,
                    'receipt_path' => $o->payment_receipt_path,
                ]);
            });
        }

        if ($keys !== null) {
            $rows = $rows->whereIn('key', $keys);
        }

        return $rows->sortByDesc(fn ($r) => $r['date']?->getTimestamp() ?? 0)->values();
    }

    /** Earliest date any payment record exists for the shop — "All time" starts here. */
    public static function firstRecordDate(Store $store, ?int $branchId = null): ?string
    {
        $candidates = [
            Payment::query()->whereHas('jobOrder', fn ($j) => $j->withTrashed()->where('store_id', $store->id)->when($branchId, fn ($jj) => $jj->where('store_branch_id', $branchId)))->min('created_at'),
            Appointment::where('store_id', $store->id)->whereNotNull('payment_method')->when($branchId, fn ($q) => $q->where('store_branch_id', $branchId))->min('created_at'),
            CatalogOrder::where('store_id', $store->id)->whereNotNull('payment_method')->when($branchId, fn ($q) => $q->where('store_branch_id', $branchId))->min('created_at'),
        ];
        $first = collect($candidates)->filter()->map(fn ($d) => Carbon::parse($d))->min();

        return $first?->toDateString();
    }
}
