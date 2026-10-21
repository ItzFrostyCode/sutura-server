<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "[Moved by customer from …]", "[Rescheduled from …]" and "[Completion Note] …" were typed into the notes. They become real fields.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $t) {
            $t->dateTime('previous_scheduled_at')->nullable()->after('scheduled_at');
            $t->text('visit_notes')->nullable()->after('notes');
        });

        DB::table('appointments')->whereNotNull('notes')->where('notes', 'like', '%[%')->get(['id', 'notes'])->each(function ($a) {
            $notes = (string) $a->notes;
            $previous = null;
            if (preg_match('/\[(?:Moved by customer|Rescheduled) from ([^\]]+)\]/i', $notes, $m)) {
                try {
                    $previous = Carbon::createFromFormat('M j, Y g:i A', trim($m[1]));
                } catch (\Throwable) {
                    $previous = null;
                }
            }
            $visit = null;
            if (preg_match('/\[Completion Note\]\s*([\s\S]*)$/i', $notes, $m)) {
                $visit = trim($m[1]);
            }
            $clean = preg_replace([
                '/\[(?:Moved by customer|Rescheduled) from [^\]]*\]\n?/i',
                '/\[Completion Note\][\s\S]*$/i',
                '/\[Walk-in Priority\][^\n]*\n?/i',
            ], '', $notes);
            DB::table('appointments')->where('id', $a->id)->update([
                'previous_scheduled_at' => $previous,
                'visit_notes' => $visit,
                'notes' => trim((string) $clean) !== '' ? trim((string) $clean) : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn(['previous_scheduled_at', 'visit_notes']));
    }
};
