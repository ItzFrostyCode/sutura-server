<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Consultation first: what the customer wants is written down during the visit (design source, how many people,
// notes) and carried onto the production order, so nothing that was said is lost.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('design_source', 20)->nullable();
            $table->string('design_link', 1000)->nullable();
            $table->unsignedSmallInteger('people_count')->nullable();
        });
        Schema::table('job_orders', function (Blueprint $table) {
            $table->string('design_source', 20)->nullable();
            $table->string('design_link', 1000)->nullable();
            $table->text('consultation_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn(['design_source', 'design_link', 'people_count']));
        Schema::table('job_orders', fn (Blueprint $table) => $table->dropColumn(['design_source', 'design_link', 'consultation_notes']));
    }
};
