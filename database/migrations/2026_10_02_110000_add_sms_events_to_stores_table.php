<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which kinds of text a shop wants sent. null = the recommended few (see SmsTemplates::DEFAULT_ENABLED).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', fn (Blueprint $t) => $t->json('sms_events')->nullable());
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $t) => $t->dropColumn('sms_events'));
    }
};
