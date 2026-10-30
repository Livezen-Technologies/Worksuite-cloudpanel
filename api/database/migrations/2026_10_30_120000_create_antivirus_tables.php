<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antivirus (SRS 4.25: AV-4 threat list, AV-5 quarantine, AV-7 false positives).
 * A threat belongs to the organisation that owns the files: the app's owner for an
 * app volume, otherwise the volume's organisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threats', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $t->foreignUuid('volume_id')->nullable()->constrained('volumes')->nullOnDelete();
            $t->string('volume_name');
            $t->string('path', 1000);
            $t->string('signature');
            $t->string('sha256', 64);
            $t->unsignedBigInteger('size')->default(0);
            $t->string('status')->default('infected');   // infected|quarantined|restored|deleted|ignored
            $t->timestamp('detected_at');
            $t->timestamp('resolved_at')->nullable();
            $t->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['organization_id', 'status']);
            $t->index(['server_id', 'volume_name', 'sha256']);
        });

        Schema::create('antivirus_allowlist', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('sha256', 64);
            $t->string('reason')->nullable();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antivirus_allowlist');
        Schema::dropIfExists('threats');
    }
};
