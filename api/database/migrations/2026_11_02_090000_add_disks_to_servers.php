<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * disk_gb/disk_pct describe the root filesystem only, so a server whose data
 * sits on a second array was reported as if that array did not exist. The
 * agent now sends every mounted local filesystem and this column keeps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->json('disks')->nullable()->after('disk_pct');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->dropColumn('disks');
        });
    }
};
