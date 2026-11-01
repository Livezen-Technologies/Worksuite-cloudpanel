<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\GitConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** GIT: talks to GitHub, GitLab, Gitea and Bitbucket with a stored access token. */
class GitProviderService
{
    public const PROVIDERS = ['github', 'gitlab', 'gitea', 'bitbucket'];

    public function api(GitConnection $c): string
    {
        $base = rtrim((string) $c->base_url, '/');

        return match ($c->provider) {
            'github' => $base !== '' ? $base.'/api/v3' : 'https://api.github.com',
            'gitlab' => ($base !== '' ? $base : 'https://gitlab.com').'/api/v4',
            'gitea' => $base.'/api/v1',
            'bitbucket' => 'https://api.bitbucket.org/2.0',
        };
    }

    private function http(GitConnection $c): PendingRequest
    {
        $h = Http::timeout(10)->acceptJson()->withUserAgent(config('app.name').'-git');

        return match ($c->provider) {
            'bitbucket' => $h->withBasicAuth((string) $c->username, (string) $c->token),
            'gitea' => $h->withHeaders(['Authorization' => 'token '.$c->token]),
            default => $h->withToken((string) $c->token),
        };
    }

    /** Checks the token and records the account it belongs to. */
    public function verify(GitConnection $c): GitConnection
    {
        try {
            $r = $this->http($c)->get($this->api($c).'/user');
            if (! $r->successful()) {
                throw new \RuntimeException($r->status() === 401 ? 'The token was rejected.' : "The provider answered HTTP {$r->status()}.");
            }
            $account = (string) ($r->json('login') ?? $r->json('username') ?? $r->json('display_name') ?? '');
            $c->forceFill(['account' => $account, 'status' => 'connected', 'error' => null, 'checked_at' => now()])->save();
        } catch (\Throwable $e) {
            $msg = $e instanceof \Illuminate\Http\Client\ConnectionException ? 'The provider could not be reached.' : $e->getMessage();
            $c->forceFill(['status' => 'failed', 'error' => mb_substr($msg, 0, 500), 'checked_at' => now()])->save();
        }

        return $c;
    }

    /** @return list<array{name:string, clone_url:string, ssh_url:?string, default_branch:?string, private:bool}> */
    public function repositories(GitConnection $c): array
    {
        $r = $this->http($c)->get($this->api($c).match ($c->provider) {
            'github' => '/user/repos?per_page=100&sort=updated',
            'gitlab' => '/projects?membership=true&per_page=100&order_by=last_activity_at',
            'gitea' => '/user/repos?limit=50',
            'bitbucket' => '/repositories?role=member&pagelen=100',
        });
        if (! $r->successful()) {
            throw ApiException::conflict("The provider answered HTTP {$r->status()} when listing repositories.");
        }
        $items = $c->provider === 'bitbucket' ? (array) $r->json('values', []) : (array) $r->json();

        return array_values(array_map(fn ($x) => match ($c->provider) {
            'gitlab' => ['name' => (string) ($x['path_with_namespace'] ?? ''), 'clone_url' => (string) ($x['http_url_to_repo'] ?? ''), 'ssh_url' => $x['ssh_url_to_repo'] ?? null, 'default_branch' => $x['default_branch'] ?? null, 'private' => ($x['visibility'] ?? '') !== 'public'],
            'bitbucket' => ['name' => (string) ($x['full_name'] ?? ''), 'clone_url' => (string) (collect($x['links']['clone'] ?? [])->firstWhere('name', 'https')['href'] ?? ''), 'ssh_url' => collect($x['links']['clone'] ?? [])->firstWhere('name', 'ssh')['href'] ?? null, 'default_branch' => $x['mainbranch']['name'] ?? null, 'private' => (bool) ($x['is_private'] ?? true)],
            default => ['name' => (string) ($x['full_name'] ?? ''), 'clone_url' => (string) ($x['clone_url'] ?? ''), 'ssh_url' => $x['ssh_url'] ?? null, 'default_branch' => $x['default_branch'] ?? null, 'private' => (bool) ($x['private'] ?? true)],
        }, $items));
    }
}
