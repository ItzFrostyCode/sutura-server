<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Job order numbers came in two shapes: demo data used JO-1001…, while orders
// made through the app use JO-{year}-{sequence}. Put every store on the app's
// format, keeping the oldest orders first, so numbers read the same everywhere
// (and the generator, which continues from the highest JO-{year}- number,
// carries on from the end of the list).
return new class extends Migration
{
    public function up(): void
    {
        $stores = DB::table('job_orders')->whereRaw("order_number REGEXP '^JO-1[0-9]{3}$'")->distinct()->pluck('store_id');

        foreach ($stores as $storeId) {
            DB::transaction(function () use ($storeId) {
                $rows = DB::table('job_orders')
                    ->where('store_id', $storeId)
                    ->where(function ($q) {
                        $q->whereRaw("order_number REGEXP '^JO-1[0-9]{3}$'")->orWhere('order_number', 'like', 'JO-2026-%');
                    })
                    ->orderByRaw("order_number REGEXP '^JO-1[0-9]{3}$' DESC") // demo orders first
                    ->orderBy('id')
                    ->get(['id']);

                // Two passes so no half-renamed pair ever collides.
                foreach ($rows as $row) {
                    DB::table('job_orders')->where('id', $row->id)->update(['order_number' => "TMP-{$row->id}"]);
                }
                foreach ($rows->values() as $i => $row) {
                    DB::table('job_orders')->where('id', $row->id)->update([
                        'order_number' => 'JO-2026-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        // Numbers are identifiers, not data worth restoring.
    }
};
