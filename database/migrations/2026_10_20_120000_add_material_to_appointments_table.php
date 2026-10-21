<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The fabric choice a booking carried inside its notes ("[Material: …]") becomes two real columns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $t) {
            $t->string('material_source', 10)->nullable()->after('answers'); // shop | own
            $t->string('material_note', 255)->nullable()->after('material_source');
        });

        // Move what old bookings said out of their notes; the notes keep only what the person wrote.
        DB::table('appointments')->whereNotNull('notes')->where('notes', 'like', '%[%')->get(['id', 'notes'])->each(function ($a) {
            $notes = (string) $a->notes;
            $source = null;
            $note = null;
            if (preg_match('/\[Material:\s*Customer will bring own fabric\/sample(?:\s*[-—]\s*([^\]]+))?\]/i', $notes, $m)) {
                $source = 'own';
                $note = isset($m[1]) ? trim($m[1]) : null;
            } elseif (preg_match("/\[Material:\s*Customer will use the shop's material\]/i", $notes)) {
                $source = 'shop';
            }
            $clean = preg_replace([
                '/\[Requested Consultation:[\s\S]*?\]\n?/i',
                '/\[Material:[^\]]*\]\n?/i',
                '/\[Quantity:[^\]]*\]\n?/i',
                '/\[Design Reference:[^\]]*\]\n?/i',
                '/^\[Walk-in Priority\] Customer arrived in-store\.\n?/i',
            ], '', $notes);
            DB::table('appointments')->where('id', $a->id)->update(['material_source' => $source, 'material_note' => $note, 'notes' => trim($clean) !== '' ? trim($clean) : null]);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn(['material_source', 'material_note']));
    }
};
