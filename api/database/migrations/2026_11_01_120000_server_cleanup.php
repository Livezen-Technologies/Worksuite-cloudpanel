<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CLN-1..5: per-server cleanup policy and run history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->json('cleanup')->nullable());
        Schema::create('cleanup_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $t->uuid('job_id')->nullable()->index();
            $t->string('level', 12);
            $t->string('trigger', 12); // manual|disk|preview
            $t->string('status', 12)->default('running'); // running|succeeded|failed
            $t->float('disk_pct')->nullable();
            $t->unsignedBigInteger('freed_bytes')->nullable();
            $t->json('result')->nullable();
            $t->text('message')->nullable();
            $t->foreignUuid('user_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleanup_runs');
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('cleanup'));
    }
};
