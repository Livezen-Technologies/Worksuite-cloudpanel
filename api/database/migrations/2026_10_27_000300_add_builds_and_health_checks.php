<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Builder servers (SRS 2.4), real image builds/scans (APP-001/002, IMG-003) and health checks (UHOST-006). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->json('roles')->nullable(); // ["app"] (default), "builder"
        });
        Schema::table('applications', function (Blueprint $t) {
            $t->string('health_path')->nullable();      // HTTP path probed after start (default "/")
            $t->integer('health_timeout')->nullable();  // seconds (default 60)
        });
        Schema::table('deployments', function (Blueprint $t) {
            $t->string('image_digest')->nullable();
            $t->uuid('builder_server_id')->nullable();
            $t->json('scan_result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', fn (Blueprint $t) => $t->dropColumn(['image_digest', 'builder_server_id', 'scan_result']));
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn(['health_path', 'health_timeout']));
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('roles'));
    }
};
