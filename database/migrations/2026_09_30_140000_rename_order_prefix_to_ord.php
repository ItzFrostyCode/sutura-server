<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Final order-number format: ORD-0001, ORD-0002, … one series for walk-in
// sales and job orders alike (no year, no separate JO- prefix).
return new class extends Migration
{
    public function up(): void
    {
        foreach (['job_orders', 'catalog_orders'] as $table) {
            foreach (DB::table($table)->where('order_number', 'like', 'JO-%')->get(['id', 'order_number']) as $row) {
                if (preg_match('/^JO-(\d+)$/', (string) $row->order_number, $m)) {
                    DB::table($table)->where('id', $row->id)->update([
                        'order_number' => 'ORD-'.str_pad($m[1], 4, '0', STR_PAD_LEFT),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Identifiers only; nothing to restore.
    }
};
