<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AV-2 / AV-12: per-server real-time scanning and outbound abuse limits. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->json('security')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->dropColumn('security');
        });
    }
};
