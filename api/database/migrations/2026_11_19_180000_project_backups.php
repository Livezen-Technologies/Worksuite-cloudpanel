<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project backups: one parent run (target_type "project") whose children are the ordinary backups of
 * each application in the project, plus a configuration snapshot (metadata). A project restore groups
 * the per-application restores it starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $t) {
            $t->uuid('parent_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->json('metadata')->nullable();
        });
        Schema::table('restore_jobs', function (Blueprint $t) {
            $t->uuid('project_backup_job_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->dropColumn(['parent_id', 'project_id', 'metadata']));
        Schema::table('restore_jobs', fn (Blueprint $t) => $t->dropColumn('project_backup_job_id'));
    }
};
