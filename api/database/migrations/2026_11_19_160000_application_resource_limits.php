<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-app CPU/RAM limits; null falls back to the app's size (plan). Docker enforces them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $t) {
            $t->float('cpu_limit')->nullable();
            $t->unsignedInteger('memory_limit_mb')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn(['cpu_limit', 'memory_limit_mb']));
    }
};
