<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every customer application belongs to a project and an environment.
 *
 * Customer apps were created with the bare string `environment = 'production'`
 * and a null `environment_id`, while everything environment-shaped keys on the
 * foreign key. That cut them off from push-deploys, `${{service.HOST}}`
 * discovery, promotion and previews. This backfills the ones already created.
 *
 * Written with the query builder rather than Eloquent on purpose: the models
 * carry an organisation global scope that is not bound during a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->boolean('is_default')->default(false));

        // Push-deploys are now gated on the portal's toggle, for customer apps
        // only. It defaults to false and nothing ever read it, so existing
        // customer apps built from a repository are opted in to match what a
        // new one gets. Admin apps are untouched: there the same column means
        // "deploy on a new release" and push behaviour must not change.

        DB::table('applications')->whereNull('deleted_at')->where('kind', 'customer')
            ->where('source_type', 'git')->update(['auto_deploy' => true]);

        foreach (DB::table('organizations')->pluck('id') as $orgId) {
            foreach (DB::table('applications')->where('organization_id', $orgId)->where('kind', 'customer')
                ->whereNull('deleted_at')->whereNull('environment_id')->get(['id', 'project_id', 'environment']) as $app) {
                $projectId = $app->project_id ?: $this->defaultProject($orgId);
                $env = $this->environment($orgId, $projectId, $app->environment ?: 'production');
                DB::table('applications')->where('id', $app->id)
                    ->update(['project_id' => $projectId, 'environment_id' => $env->id, 'environment' => $env->kind]);
            }
        }
    }

    /** The organisation's catch-all project: an existing one is adopted before a new one is made. */
    private function defaultProject(string $orgId): string
    {
        $existing = DB::table('projects')->where('organization_id', $orgId)->orderBy('created_at')->first(['id']);
        if ($existing) {
            DB::table('projects')->where('id', $existing->id)->update(['is_default' => true]);

            return $existing->id;
        }
        $id = (string) Illuminate\Support\Str::uuid();
        DB::table('projects')->insert([
            'id' => $id, 'organization_id' => $orgId, 'name' => 'Default', 'is_default' => true,
            'description' => 'Apps that have not been put in a project of their own.',
            'webhook_secret' => Illuminate\Support\Str::random(40),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function environment(string $orgId, string $projectId, string $kind): object
    {
        $envs = DB::table('environments')->where('project_id', $projectId)->orderBy('created_at')->get(['id', 'kind']);
        $env = $envs->firstWhere('kind', $kind) ?? $envs->firstWhere('kind', 'production') ?? $envs->first();
        if ($env) {
            return $env;
        }
        $id = (string) Illuminate\Support\Str::uuid();
        DB::table('environments')->insert([
            'id' => $id, 'organization_id' => $orgId, 'project_id' => $projectId,
            'name' => ucfirst($kind), 'slug' => $kind, 'kind' => $kind,
            'requires_approval' => $kind === 'production', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['id' => $id, 'kind' => $kind];
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('is_default'));
    }
};
