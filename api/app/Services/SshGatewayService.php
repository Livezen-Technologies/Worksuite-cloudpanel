<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Container;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserSshKey;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * SSH-GW: a shell over SSH is an ordinary terminal session. The relay's SSH
 * listener names the key and the target (the SSH user name), and this turns
 * that into the same session the web terminal opens: same permissions and
 * approvals, non-root user, time limit, audit entry and recording.
 */
class SshGatewayService
{
    public function __construct(private Tenant $tenant, private ContainerService $containers, private Authorizer $auth) {}

    public static function info(): array
    {
        return [
            'enabled' => (bool) config('security.ssh_gateway.enabled'),
            'host' => (string) config('security.ssh_gateway.host'),
            'port' => (int) config('security.ssh_gateway.port'),
        ];
    }

    private function keyOwner(string $fingerprint): array
    {
        $key = UserSshKey::where('fingerprint', $fingerprint)->first();
        $user = $key ? User::find($key->user_id) : null;
        if (! $key || ! $user) {
            throw new ApiException('unauthenticated', 'This SSH key is not registered. Add it under Account → SSH keys.', 401);
        }

        return [$key, $user];
    }

    /** Running containers the person may open a shell on, in every organisation they belong to. */
    public function targets(User $user): Collection
    {
        $out = collect();
        foreach (Membership::withoutGlobalScope('organization')->where('user_id', $user->id)->with('organization')->get() as $m) {
            $user->setAttribute('current_organization_id', $m->organization_id);
            if (! $user->grant('terminal')) {
                continue;
            }
            $this->tenant->as($m->organization_id, function () use ($user, $m, $out) {
                $q = Container::where('state', 'running')->whereNotNull('docker_id')->orderBy('name')->with('application:id,name');
                $this->auth->scopeAssigned($user, 'terminal', $q);
                foreach ($q->limit(200)->get() as $c) {
                    $out->push(['organization_id' => $m->organization_id, 'org' => $m->organization?->slug, 'name' => $c->name, 'app' => $c->application?->name, 'id' => $c->id]);
                }
            });
        }

        return $out;
    }

    /** `name`, `app-name` or `name+org-slug`: a container name, or the app it belongs to. */
    private function resolve(User $user, string $target): array
    {
        [$name, $org] = array_pad(explode('+', $target, 2), 2, null);
        $hits = $this->targets($user)->filter(fn ($t) => ($t['name'] === $name || $t['app'] === $name) && ($org === null || $t['org'] === $org))->values();
        if ($hits->isEmpty()) {
            throw new ApiException('not_found', "No running container called {$name} that you may open a shell on. Connect as 'list' to see yours.", 404);
        }
        if ($hits->pluck('id')->unique()->count() > 1) {
            $names = $hits->map(fn ($t) => $t['name'].'+'.$t['org'])->unique()->implode(', ');
            throw ApiException::conflict("More than one container matches {$name}; connect as one of: {$names}");
        }

        return $hits->first();
    }

    /** @return array{targets: list<string>} */
    public function list(string $fingerprint): array
    {
        [$key, $user] = $this->keyOwner($fingerprint);
        $key->forceFill(['last_used_at' => now()])->save();

        return ['user' => $user->email, 'targets' => $this->targets($user)->map(fn ($t) => $t['name'].($t['app'] && $t['app'] !== $t['name'] ? " (app {$t['app']})" : '').' +'.$t['org'])->values()->all()];
    }

    /** Opens a terminal session for the key's owner; the relay then connects it like a browser would. */
    public function open(string $fingerprint, string $target, ?string $ip): array
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}(\+[a-z0-9-]{1,80})?$/', $target)) {
            throw ApiException::validation(['target' => ['Connect as the container or app name, for example ssh shop-web@panel.example.com.']]);
        }
        [$key, $user] = $this->keyOwner($fingerprint);
        $t = $this->resolve($user, $target);
        $user->setAttribute('current_organization_id', $t['organization_id']);

        return $this->tenant->as($t['organization_id'], function () use ($user, $t, $key, $ip) {
            $container = Container::findOrFail($t['id']);
            $this->auth->authorize($user, 'terminal', $container, true);
            // An approval granted since the last attempt is used now, once.
            $approved = \App\Models\Approval::where('action', 'terminal.open')->where('status', 'approved')->where('requested_by', $user->id)
                ->where('resource_id', $container->id)->latest()->get()->first(fn ($a) => empty($a->result['consumed']));
            try {
                $s = $this->containers->openTerminal($user, $container, $approved?->id);
            } catch (\App\Exceptions\ApprovalRequired) {
                throw new ApiException('approval_required', "Opening a shell on {$container->name} needs a second person's approval. A request was sent; connect again once it is approved.", 403);
            }
            parse_str((string) parse_url($s['ws_url'], PHP_URL_QUERY), $q);
            \App\Models\TerminalSession::allTenants()->whereKey($s['session_id'])->update(['ip' => $ip]);
            $key->forceFill(['last_used_at' => now()])->save();
            app(AuditLogger::class)->log('terminal.ssh', 'containers', $container->id, ['session_id' => $s['session_id'], 'key' => $key->fingerprint, 'ip' => $ip], $t['organization_id'], $user->id, $user->email);

            return ['session_id' => $s['session_id'], 'token' => $q['token'] ?? '', 'container' => $container->name, 'user' => $user->email];
        });
    }
}
