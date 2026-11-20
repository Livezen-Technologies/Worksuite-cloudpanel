<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Deployment;
use Illuminate\Support\Facades\Process;

/**
 * Updates from the repository: compares the commit an app was last built from with the head of
 * its branch, and (when the app opts in) rebuilds it as soon as the branch moves.
 */
class GitUpdateService
{
    public function __construct(private BuildService $builds) {}

    /** The head commit of the app's branch, or null when the repository cannot be read. */
    public function remoteHead(Application $app): ?string
    {
        $src = (array) $app->source;
        $repo = (string) ($src['repo'] ?? '');
        if (! str_starts_with($repo, 'https://')) {
            return null; // SSH repositories need the deploy key, which only the builder holds
        }
        $ref = (string) ($src['ref'] ?? $src['branch'] ?? 'main');
        $auth = null;
        if (! empty($src['template_git'])) {
            $auth = $this->builds->catalogueAuth($repo);
        } elseif (! empty($src['git_connection_id']) && ($c = \App\Models\GitConnection::find($src['git_connection_id']))) {
            $auth = ['username' => match ($c->provider) { 'bitbucket' => (string) $c->username, 'gitlab' => 'oauth2', default => 'x-access-token' }, 'token' => (string) $c->token];
        }
        $cmd = ['git'];
        if ($auth) {
            $cmd = array_merge($cmd, ['-c', 'http.extraHeader=Authorization: Basic '.base64_encode($auth['username'].':'.$auth['token'])]);
        }
        $r = Process::timeout(20)->env(['GIT_TERMINAL_PROMPT' => '0'])->run(array_merge($cmd, ['ls-remote', $repo, 'refs/heads/'.$ref, $ref]));
        if (! $r->successful() || ! preg_match('/^([0-9a-f]{40})\s/m', $r->output(), $m)) {
            return null;
        }

        return $m[1];
    }

    /** Checks one app and records the result in its source; returns the update state. */
    public function check(Application $app): array
    {
        $head = $this->remoteHead($app);
        $src = (array) $app->source;
        $src['update_checked_at'] = now()->toIso8601ZuluString();
        $src['update_check_error'] = $head ? null : 'The repository could not be read.';
        if ($head) {
            $src['remote_commit'] = $head;
        }
        $app->forceFill(['source' => $src])->save();

        return $this->state($app);
    }

    public function state(Application $app): array
    {
        $src = (array) $app->source;
        $built = $src['built_commit'] ?? null;
        $remote = $src['remote_commit'] ?? null;

        return [
            'built_commit' => $built, 'remote_commit' => $remote,
            'available' => (bool) ($built && $remote && ! str_starts_with($remote, $built) && ! str_starts_with($built, $remote)),
            'auto_update' => (bool) ($src['auto_update'] ?? false),
            'checked_at' => $src['update_checked_at'] ?? null, 'error' => $src['update_check_error'] ?? null,
        ];
    }

    /** Scheduled: check every Git-built app; rebuild those that opted in and have a newer commit. */
    public function checkAll(): array
    {
        $stats = ['checked' => 0, 'available' => 0, 'deployed' => 0];
        Application::allTenants()->whereIn('source_type', ['git', 'dockerfile'])->get()->each(function (Application $app) use (&$stats) {
            if (empty($app->source['repo'])) {
                return;
            }
            app(\App\Support\Tenant::class)->as($app->organization_id, function () use ($app, &$stats) {
                $s = $this->check($app);
                $stats['checked']++;
                if (! $s['available']) {
                    return;
                }
                $stats['available']++;
                $busy = Deployment::where('application_id', $app->id)->whereIn('status', ['pending', 'queued', 'running', 'building'])->exists();
                if ($s['auto_update'] && ! $busy) {
                    try {
                        app(DeploymentService::class)->start($app, null, 'auto-update');
                        $stats['deployed']++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
        });

        return $stats;
    }
}
