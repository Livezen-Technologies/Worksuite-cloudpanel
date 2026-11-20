<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Services\GitUpdateService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** Updates from the repository: a newer commit on the branch is reported, and rebuilt when the app opts in. */
class GitUpdateTest extends TestCase
{
    private function gitApp($org, array $source): Application
    {
        return app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'ws', 'source_type' => 'git', 'port' => 80,
            'source' => ['repo' => 'https://github.com/acme/ws.git', 'ref' => 'main'] + $source]));
    }

    public function test_a_newer_commit_is_reported_and_the_check_endpoint_records_it(): void
    {
        [, $org, $token] = $this->org();
        $app = $this->gitApp($org, ['built_commit' => str_repeat('a', 40)]);
        Process::fake(['*ls-remote*' => Process::result(str_repeat('b', 40)."\trefs/heads/main\n")]);

        $u = $this->api($token)->postJson("/api/v1/applications/{$app->id}/git/check-update")->assertOk()->json('data.update');
        $this->assertTrue($u['available']);
        $this->assertSame(str_repeat('b', 40), $u['remote_commit']);
        $this->assertSame('main', $this->api($token)->getJson("/api/v1/applications/{$app->id}/git")->json('data.branch'));

        $this->api($token)->patchJson("/api/v1/applications/{$app->id}/git/auto-update", ['auto_update' => true])->assertOk()->assertJsonPath('data.update.auto_update', true);
    }

    public function test_same_commit_is_up_to_date_and_unreadable_repositories_say_so(): void
    {
        [, $org] = $this->org();
        $app = $this->gitApp($org, ['built_commit' => str_repeat('c', 12)]);
        Process::fake(['*ls-remote*' => Process::result(str_repeat('c', 40)."\trefs/heads/main\n")]);
        $s = app(Tenant::class)->as($org->id, fn () => app(GitUpdateService::class)->check($app));
        $this->assertFalse($s['available']);

        Process::fake(['*ls-remote*' => Process::result('', 'fatal: auth', 128)]);
        $s = app(Tenant::class)->as($org->id, fn () => app(GitUpdateService::class)->check($app->fresh()));
        $this->assertNotNull($s['error']);
    }

    public function test_scheduled_check_rebuilds_only_apps_set_to_update_automatically(): void
    {
        [, $org] = $this->org();
        $server = $this->server($org);
        $auto = $this->gitApp($org, ['built_commit' => str_repeat('a', 40), 'auto_update' => true]);
        app(Tenant::class)->as($org->id, fn () => $auto->update(['server_id' => $server->id]));
        $manual = app(Tenant::class)->as($org->id, fn () => Application::create(['name' => 'other', 'source_type' => 'git', 'port' => 80, 'server_id' => $server->id,
            'source' => ['repo' => 'https://github.com/acme/other.git', 'ref' => 'main', 'built_commit' => str_repeat('a', 40)]]));
        Process::fake(['*ls-remote*' => Process::result(str_repeat('d', 40)."\trefs/heads/main\n")]);

        $this->artisan('apps:check-git-updates')->assertSuccessful();
        $this->assertSame(1, Deployment::allTenants()->where('application_id', $auto->id)->count());
        $this->assertSame(0, Deployment::allTenants()->where('application_id', $manual->id)->count());
    }
}
