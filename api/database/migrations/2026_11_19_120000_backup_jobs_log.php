<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Backup manager → Logs: the run log of each backup job (external jobs send theirs). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->text('log')->nullable());
    }

    public function down(): void
    {
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->dropColumn('log'));
    }
};
