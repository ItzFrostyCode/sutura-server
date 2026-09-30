<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One order series for the whole store: JO-0001, JO-0002, … shared by walk-in
// sales (catalog orders) and job orders, with no year in it. Job orders drop
// the year (JO-2026-0007 → JO-0007); walk-in sales, which had no number,
// continue the series in the order they were made.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->string('order_number', 50)->nullable()->after('id');
        });

        $storeIds = DB::table('job_orders')->pluck('store_id')
            ->merge(DB::table('catalog_orders')->pluck('store_id'))
            ->unique();

        foreach ($storeIds as $storeId) {
            DB::transaction(function () use ($storeId) {
                $max = 0;

                foreach (DB::table('job_orders')->where('store_id', $storeId)->get(['id', 'order_number']) as $row) {
                    if (preg_match('/^JO-(?:\d{4}-)?(\d+)$/', (string) $row->order_number, $m)) {
                        $n = (int) $m[1];
                        $max = max($max, $n);
                        DB::table('job_orders')->where('id', $row->id)->update([
                            'order_number' => 'JO-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                        ]);
                    }
                }

                $orders = DB::table('catalog_orders')->where('store_id', $storeId)->orderBy('created_at')->orderBy('id')->get(['id']);
                foreach ($orders as $order) {
                    $max++;
                    DB::table('catalog_orders')->where('id', $order->id)->update([
                        'order_number' => 'JO-'.str_pad((string) $max, 4, '0', STR_PAD_LEFT),
                    ]);
                }
            });
        }

        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->unique(['store_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_orders', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'order_number']);
            $table->dropColumn('order_number');
        });
    }
};
