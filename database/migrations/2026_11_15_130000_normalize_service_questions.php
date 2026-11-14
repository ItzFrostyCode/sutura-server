<?php

use App\Support\OfferingDataRepair;
use Illuminate\Database\Migrations\Migration;

/**
 * Rewrites every service's questions and list columns in the one current shape ({id, label, type, required,
 * options}). Older rows ({name, type: dropdown|short_text}) showed as a text box instead of choices in the online
 * order and had no id. The model already reads them correctly (ServiceQuestionsCast); this makes the stored data match.
 */
return new class extends Migration
{
    public function up(): void
    {
        OfferingDataRepair::questionsAndTypes();
    }

    public function down(): void
    {
        // The old shape is not restored: the current one is read by every screen.
    }
};
