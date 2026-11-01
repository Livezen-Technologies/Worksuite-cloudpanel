<?php

namespace App\Services\Ai;

use App\Exceptions\ApiException;
use App\Models\AiAction;
use App\Models\Application;
use App\Models\Container;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ContainerService;
use App\Services\DeploymentService;
use App\Support\Tenant;

/**
 * UAI: the tools the assistant and MCP clients may call on a customer's own apps.
 * Reads always run. Changes follow the organisation's AI level: read_only refuses them,
 * ask parks them for a person's approval, autonomous runs them.
 */
class AiToolService
{
    public const LEVELS = ['read_only', 'ask', 'autonomous'];

    public const DEFAULTS = ['level' => 'ask', 'confirm_destructive' => true, 'hide_secrets' => true, 'audit' => true, 'assistant' => true];

    public function settings(Organization $org): array
    {
        return array_replace(self::DEFAULTS, (array) (($org->settings ?? [])['ai'] ?? []));
    }

    public function saveSettings(Organization $org, array $v): array
    {
        $s = $org->settings ?? [];
        $s['ai'] = array_replace($this->settings($org), array_intersect_key($v, self::DEFAULTS));
        $org->forceFill(['settings' => $s])->save();

        return $s['ai'];
    }

    /** @return array<string, array{description:string, write:bool, schema:array}> */
    public function tools(): array
    {
        $app = ['type' => 'object', 'properties' => ['app' => ['type' => 'string', 'description' => 'App name or id']], 'required' => ['app']];

        return [
            'list_projects' => ['description' => 'List your projects and the apps in each.', 'write' => false, 'schema' => ['type' => 'object']],
            'list_apps' => ['description' => 'List your apps with status, region and project.', 'write' => false, 'schema' => ['type' => 'object']],
            'get_app' => ['description' => 'Details of one app: status, image, size, domains.', 'write' => false, 'schema' => $app],
            'get_logs' => ['description' => 'The latest log lines of one app.', 'write' => false, 'schema' => ['type' => 'object', 'properties' => ['app' => ['type' => 'string'], 'tail' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500]], 'required' => ['app']]],
            'restart_app' => ['description' => 'Restart one app. A change: may need approval.', 'write' => true, 'schema' => $app],
            'redeploy_app' => ['description' => 'Redeploy one app from its current source. A change: may need approval.', 'write' => true, 'schema' => $app],
        ];
    }

    /** Runs or parks a tool call and records it. Returns [status, result|message, action]. */
    public function call(User $user, string $client, ?string $tokenId, string $tool, array $args, bool $tokenMayWrite): array
    {
        $def = $this->tools()[$tool] ?? throw ApiException::validation(['tool' => ["Unknown tool {$tool}."]]);
        $org = Organization::findOrFail(app(Tenant::class)->id());
        $s = $this->settings($org);
        $target = $this->targetOf($tool, $args);
        $record = fn (string $status, ?array $result = null, ?string $error = null) => AiAction::create([
            'user_id' => $user->id, 'token_id' => $tokenId, 'client' => mb_substr($client, 0, 120), 'tool' => $tool, 'target' => $target,
            'arguments' => $args, 'status' => $status, 'result' => $result, 'error' => $error ? mb_substr($error, 0, 500) : null,
        ]);

        if ($def['write'] && (! $tokenMayWrite || $s['level'] === 'read_only')) {
            $why = ! $tokenMayWrite ? 'This access token is read-only.' : 'AI is set to read-only for this account.';
            $a = $record('refused', null, $why);

            return ['refused', $why, $a];
        }
        if ($def['write'] && $s['level'] === 'ask') {
            $a = $record('pending');

            return ['pending', "Waiting for approval in the panel (AI assistant → Activity). Request {$a->id}.", $a];
        }
        try {
            $result = $this->run($user, $tool, $args);
        } catch (\Throwable $e) {
            $msg = $e instanceof ApiException || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? $this->message($e) : 'The tool failed.';
            $a = $record('failed', null, $msg);

            return ['failed', $msg, $a];
        }
        $a = $record('done', $def['write'] ? $result : null);
        if ($def['write']) {
            app(AuditLogger::class)->log("ai.{$tool}", 'application', $result['app_id'] ?? null, ['client' => $client, 'ai_action_id' => $a->id]);
        }

        return ['done', $result, $a];
    }

    public function approve(AiAction $a, User $by): AiAction
    {
        if ($a->status !== 'pending') {
            throw ApiException::conflict('This request was already decided.');
        }
        $a->forceFill(['decided_by' => $by->id, 'decided_at' => now()]);
        $owner = User::find($a->user_id) ?? $by;
        try {
            $a->forceFill(['status' => 'done', 'result' => $this->run($owner, $a->tool, (array) $a->arguments)])->save();
            app(AuditLogger::class)->log("ai.{$a->tool}", 'application', $a->result['app_id'] ?? null, ['client' => $a->client, 'ai_action_id' => $a->id, 'approved' => true]);
        } catch (\Throwable $e) {
            $a->forceFill(['status' => 'failed', 'error' => $this->message($e)])->save();
        }

        return $a;
    }

    public function reject(AiAction $a, User $by): AiAction
    {
        if ($a->status !== 'pending') {
            throw ApiException::conflict('This request was already decided.');
        }
        $a->forceFill(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now()])->save();

        return $a;
    }

    private function run(User $user, string $tool, array $args): array
    {
        return match ($tool) {
            'list_projects' => Project::orderBy('name')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name,
                'apps' => Application::where('kind', 'customer')->where('project_id', $p->id)->orderBy('name')->pluck('name')->all()])->all(),
            'list_apps' => Application::where('kind', 'customer')->orderBy('name')->get()->map(fn ($a) => $this->appSummary($a))->all(),
            'get_app' => $this->appSummary($this->app($args)) + ['domains' => Domain::where('application_id', $this->app($args)->id)->pluck('name')->all()],
            'get_logs' => $this->logs($this->app($args), (int) ($args['tail'] ?? 100)),
            'restart_app' => $this->restart($this->app($args)),
            'redeploy_app' => $this->redeploy($this->app($args), $user),
        };
    }

    private function appSummary(Application $a): array
    {
        return ['id' => $a->id, 'name' => $a->name, 'status' => $a->status, 'image' => $a->image, 'size' => $a->size, 'region' => $a->region, 'project_id' => $a->project_id];
    }

    private function app(array $args): Application
    {
        $key = trim((string) ($args['app'] ?? ''));
        if ($key === '') {
            throw ApiException::validation(['app' => ['Say which app.']]);
        }
        $q = Application::where('kind', 'customer');

        return (\Illuminate\Support\Str::isUuid($key) ? $q->whereKey($key) : $q->where('name', $key))->firstOrFail();
    }

    private function logs(Application $app, int $tail): array
    {
        $c = Container::where('application_id', $app->id)->first();
        $lines = $c ? (app(ContainerService::class)->logs($c, max(1, min(500, $tail)))['lines'] ?? []) : [];

        return ['app' => $app->name, 'lines' => $lines];
    }

    private function restart(Application $app): array
    {
        $c = Container::where('application_id', $app->id)->first() ?? throw ApiException::conflict("{$app->name} has no running container to restart.");
        $job = app(ContainerService::class)->perform($c, 'restart');

        return ['app_id' => $app->id, 'app' => $app->name, 'job_id' => $job->id];
    }

    private function redeploy(Application $app, User $user): array
    {
        $d = app(DeploymentService::class)->start($app, $user, 'redeploy');

        return ['app_id' => $app->id, 'app' => $app->name, 'deployment_id' => $d->id, 'job_id' => $d->job?->id];
    }

    private function targetOf(string $tool, array $args): ?string
    {
        return isset($args['app']) ? mb_substr((string) $args['app'], 0, 200) : null;
    }

    private function message(\Throwable $e): string
    {
        return $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 'No such app in your account.' : $e->getMessage();
    }
}
