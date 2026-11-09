<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AV-10: code files that changed outside a deployment, and when each volume was baselined and checked. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('volume_id')->index();
            $t->uuid('server_id');
            $t->string('volume_name');
            $t->string('path', 2048);
            $t->string('change');                       // added | changed | removed
            $t->string('status')->default('open');      // open | accepted
            $t->timestamp('detected_at');
            $t->timestamp('resolved_at')->nullable();
            $t->uuid('resolved_by')->nullable();
            $t->timestamps();
        });
        Schema::table('volumes', function (Blueprint $t) {
            $t->timestamp('integrity_baseline_at')->nullable();
            $t->timestamp('integrity_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_changes');
        Schema::table('volumes', function (Blueprint $t) {
            $t->dropColumn(['integrity_baseline_at', 'integrity_checked_at']);
        });
    }
};
