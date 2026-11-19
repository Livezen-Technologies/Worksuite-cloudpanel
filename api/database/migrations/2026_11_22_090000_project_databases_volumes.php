<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PWS-C: databases and volumes belong to a project. Backfilled only where a link already exists:
 * a database through its container (container.project_id, else the container's app's project), a
 * volume through the containers that mount it, when every one of them is in the same project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases', fn (Blueprint $t) => $t->uuid('project_id')->nullable()->index());
        Schema::table('volumes', fn (Blueprint $t) => $t->uuid('project_id')->nullable()->index());

        $containerProject = function (?string $containerId): ?string {
            if (! $containerId) {
                return null;
            }
            $c = DB::table('containers')->where('id', $containerId)->first(['project_id', 'application_id']);
            if (! $c) {
                return null;
            }

            return $c->project_id ?: ($c->application_id ? DB::table('applications')->where('id', $c->application_id)->value('project_id') : null);
        };

        foreach (DB::table('databases')->whereNull('project_id')->whereNotNull('container_id')->get(['id', 'container_id']) as $db) {
            if ($p = $containerProject($db->container_id)) {
                DB::table('databases')->where('id', $db->id)->update(['project_id' => $p]);
            }
        }
        foreach (DB::table('volumes')->whereNull('project_id')->get(['id']) as $v) {
            $projects = DB::table('volume_mounts')->where('volume_id', $v->id)->pluck('container_id')
                ->map($containerProject)->unique()->values();
            if ($projects->count() === 1 && $projects[0]) {
                DB::table('volumes')->where('id', $v->id)->update(['project_id' => $projects[0]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('databases', fn (Blueprint $t) => $t->dropColumn('project_id'));
        Schema::table('volumes', fn (Blueprint $t) => $t->dropColumn('project_id'));
    }
};
