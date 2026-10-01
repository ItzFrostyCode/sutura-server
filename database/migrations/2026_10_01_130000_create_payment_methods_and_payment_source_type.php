<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A shop's own list of places customers can pay (GCash, Maya, banks…), and on every payment WHERE it
// was made (online / walk-in) and WHAT it was for (deposit / partial / full / balance).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // null = the whole shop; set = only that branch.
            $table->foreignId('store_branch_id')->nullable()->constrained('store_branches')->cascadeOnDelete();
            $table->string('kind', 20);            // gcash | maya | bank_transfer | other
            $table->string('name', 80);            // what customers see, e.g. "GCash", "BPI"
            $table->string('account_name', 120);
            $table->string('account_number', 80);
            $table->text('qr_path')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('source', 10)->default('walk_in')->after('payment_method');   // online | walk_in
            $table->string('type', 20)->nullable()->after('source');                      // deposit | partial | full | balance
            $table->foreignId('payment_method_id')->nullable()->after('type')->constrained('payment_methods')->nullOnDelete();
        });

        // Carry over the single GCash / bank account stores already have, so nobody starts empty.
        foreach (DB::table('stores')->get() as $s) {
            if (! empty($s->gcash_number)) {
                DB::table('payment_methods')->insert([
                    'store_id' => $s->id, 'kind' => 'gcash', 'name' => 'GCash',
                    'account_name' => $s->gcash_account_name ?: $s->name, 'account_number' => $s->gcash_number,
                    'qr_path' => $s->gcash_qr_path, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if (! empty($s->bank_account_number)) {
                DB::table('payment_methods')->insert([
                    'store_id' => $s->id, 'kind' => 'bank_transfer', 'name' => $s->bank_name ?: 'Bank transfer',
                    'account_name' => $s->bank_account_name ?: $s->name, 'account_number' => $s->bank_account_number,
                    'qr_path' => $s->bank_qr_path, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Label what each existing payment was: first of several = deposit, last = balance,
        // between = partial, a single payment that cleared the order = full.
        foreach (DB::table('payments')->whereNull('deleted_at')->whereNull('rejected_at')->select('job_order_id')->distinct()->pluck('job_order_id') as $jobId) {
            $ids = DB::table('payments')->where('job_order_id', $jobId)->whereNull('deleted_at')->whereNull('rejected_at')->orderBy('id')->pluck('id')->all();
            foreach ($ids as $i => $id) {
                $type = count($ids) === 1 ? 'full' : ($i === 0 ? 'deposit' : ($i === count($ids) - 1 ? 'balance' : 'partial'));
                DB::table('payments')->where('id', $id)->update(['type' => $type]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn(['source', 'type']);
        });
        Schema::dropIfExists('payment_methods');
    }
};
