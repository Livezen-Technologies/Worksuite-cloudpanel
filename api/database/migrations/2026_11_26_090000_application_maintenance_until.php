<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When a locked app expects to reopen; shown on its maintenance page and used for Retry-After. Cleared on unlock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->timestamp('maintenance_until')->nullable());
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn('maintenance_until'));
    }
};
