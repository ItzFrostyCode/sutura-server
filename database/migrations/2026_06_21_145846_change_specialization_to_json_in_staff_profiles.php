<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First get all existing profiles to migrate the data
        $profiles = DB::table('staff_profiles')->get();
        
        Schema::table('staff_profiles', function (Blueprint $table) {
            // In SQLite, altering column type is tricky.
            // Since it's a string(255) in SQLite it is TEXT.
            // We can just keep the column but we need to convert the data to JSON array strings.
            // Actually, we don't need to change the schema in SQLite if we just update the data,
            // but let's change it to text so it doesn't have a 255 char limit.
            $table->text('specialization')->nullable()->change();
        });

        // Convert existing 'Specialization A' to '["Specialization A"]'
        foreach ($profiles as $profile) {
            if (!empty($profile->specialization) && !str_starts_with($profile->specialization, '[')) {
                // Split by comma if any
                $skills = array_map('trim', explode(',', $profile->specialization));
                DB::table('staff_profiles')
                    ->where('id', $profile->id)
                    ->update(['specialization' => json_encode($skills)]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->string('specialization', 255)->nullable()->change();
        });
    }
};
