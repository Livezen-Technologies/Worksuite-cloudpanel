<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\GitConnection;
use App\Models\SshKey;
use App\Models\WebhookDelivery;
use App\Services\AuditLogger;
use App\Services\GitProviderService;
use App\Support\PublicUrl;
use App\Support\SshKeys;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** GIT: deploy keys, provider connections and webhook deliveries for one organisation. */
class GitSourceController extends Controller
{
    public function __construct(private GitProviderService $git, private AuditLogger $audit) {}

    private function usedBy(string $field, string $id): array
    {
        // source is stored encrypted, so it is matched here rather than in SQL.
        return Application::whereIn('source_type', ['git', 'dockerfile', 'compose'])->orderBy('name')->get(['id', 'name', 'source'])
            ->filter(fn ($a) => ($a->source[$field] ?? null) === $id)->pluck('name')->values()->all();
    }

    private function presentKey(SshKey $k): array
    {
        return ['id' => $k->id, 'name' => $k->name, 'public_key' => $k->public_key, 'fingerprint' => $k->fingerprint,
            'used_by' => $this->usedBy('git_ssh_key_id', $k->id), 'last_used_at' => $k->last_used_at?->toIso8601ZuluString(), 'created_at' => $k->created_at?->toIso8601ZuluString()];
    }

    private function presentConnection(GitConnection $c): array
    {
        return ['id' => $c->id, 'provider' => $c->provider, 'base_url' => $c->base_url, 'account' => $c->account, 'status' => $c->status, 'error' => $c->error,
            'used_by' => $this->usedBy('git_connection_id', $c->id), 'checked_at' => $c->checked_at?->toIso8601ZuluString()];
    }

    public function index()
    {
        return $this->data([
            'providers' => GitProviderService::PROVIDERS,
            'oauth' => app(\App\Services\GitOAuthService::class)->available(),
            'connections' => GitConnection::orderBy('provider')->get()->map(fn ($c) => $this->presentConnection($c))->values(),
            'keys' => SshKey::orderBy('name')->get()->map(fn ($k) => $this->presentKey($k))->values(),
            'deliveries' => WebhookDelivery::latest('created_at')->limit(50)->get(['id', 'provider', 'event', 'repo', 'ref', 'outcome', 'detail', 'created_at'])
                ->map(fn ($d) => $d->toArray() + ['created_at' => $d->created_at?->toIso8601ZuluString()])->values(),
        ]);
    }

    public function storeKey(Request $request)
    {
        $v = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/']]);
        if (SshKey::where('name', $v['name'])->exists()) {
            throw ApiException::conflict('A key with that name already exists.');
        }
        $k = SshKeys::generate($v['name']);
        $key = SshKey::create(['name' => $v['name'], 'public_key' => $k['public'], 'private_key' => $k['private'], 'fingerprint' => $k['fingerprint']]);
        $this->audit->log('git.key.create', 'ssh_key', $key->id, ['name' => $key->name, 'fingerprint' => $key->fingerprint]);

        return $this->data($this->presentKey($key), 201);
    }

    public function destroyKey(string $id)
    {
        $key = SshKey::findOrFail($id);
        if ($used = $this->usedBy('git_ssh_key_id', $key->id)) {
            throw ApiException::conflict('This key is used by '.implode(', ', $used).'. Switch those apps to another key first.', ['used_by' => $used]);
        }
        $key->delete();
        $this->audit->log('git.key.delete', 'ssh_key', $id);

        return response()->noContent();
    }

    public function storeConnection(Request $request)
    {
        $v = $request->validate([
            'provider' => ['required', Rule::in(GitProviderService::PROVIDERS)],
            'base_url' => ['nullable', 'string', 'max:255', Rule::requiredIf($request->input('provider') === 'gitea')],
            'username' => ['nullable', 'string', 'max:120', Rule::requiredIf($request->input('provider') === 'bitbucket')],
            'token' => 'required|string|min:8|max:4096',
        ]);
        if (! empty($v['base_url'])) {
            if ($why = PublicUrl::check($v['base_url'])) {
                throw ApiException::validation(['base_url' => [$why]]);
            }
            $v['base_url'] = rtrim($v['base_url'], '/');
        }
        $c = $this->git->verify(GitConnection::create($v + ['status' => 'connected']));
        $this->audit->log('git.connection.create', 'git_connection', $c->id, ['provider' => $c->provider, 'account' => $c->account, 'status' => $c->status]);

        return $this->data($this->presentConnection($c), 201);
    }

    public function check(string $id)
    {
        return $this->data($this->presentConnection($this->git->verify(GitConnection::findOrFail($id))));
    }

    public function repositories(string $id)
    {
        return $this->data($this->git->repositories(GitConnection::findOrFail($id)));
    }

    public function destroyConnection(string $id)
    {
        $c = GitConnection::findOrFail($id);
        if ($used = $this->usedBy('git_connection_id', $c->id)) {
            throw ApiException::conflict('This connection is used by '.implode(', ', $used).'. Switch those apps first.', ['used_by' => $used]);
        }
        $c->delete();
        $this->audit->log('git.connection.delete', 'git_connection', $id);

        return response()->noContent();
    }

    /** GIT: begin "Sign in with GitHub / GitLab"; the panel page to come back to rides in the state. */
    public function oauthStart(Request $request, string $provider)
    {
        $user = $request->user();
        $back = $request->routeIs('app.*') ? '/app/git' : '/admin/git';

        return $this->data(['url' => app(\App\Services\GitOAuthService::class)->start($provider, $user->current_organization_id, $user->id, $back)]);
    }

    /** The provider sends the browser here; the encrypted single-use state says whose account it is. */
    public function oauthCallback(Request $request, string $provider)
    {
        $web = rtrim((string) config('platform.panel_url') ?: config('app.url'), '/');
        if ($request->filled('error')) {
            return redirect()->away($web.'/admin/git?git_error='.rawurlencode((string) $request->input('error')));
        }
        try {
            $r = app(\App\Services\GitOAuthService::class)->finish($provider, (string) $request->input('code'), (string) $request->input('state'));
        } catch (\App\Exceptions\ApiException $e) {
            return redirect()->away($web.'/admin/git?git_error='.rawurlencode($e->getMessage()));
        }
        $path = in_array($r['return'], ['/app/git', '/admin/git'], true) ? $r['return'] : '/admin/git';

        return redirect()->away($web.$path.'?connected='.$provider);
    }
}
