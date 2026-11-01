<?php

namespace App\Services;

use App\Contracts\SourceValidator;
use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Registry;
use App\Models\Server;
use App\Support\Tenant;

/**
 * Checks for the New application wizard, grouped by the wizard step they belong to
 * (source, build, deploy). Nothing is created; secrets are used only to test access.
 */
class ApplicationPreflight
{
    public function __construct(private SourceValidator $validator, private Scheduler $scheduler, private TemplateService $templates) {}

    /** @return array{ok: bool, failed: int, warnings: int, checks: array<int, array>, detected: array, platform_domain: string} */
    public function run(array $d): array
    {
        $checks = [];
        $detected = [];
        $add = function (string $key, string $step, string $status, string $label, string $message) use (&$checks) {
            $checks[] = compact('key', 'step', 'status', 'label', 'message');
        };
        $type = (string) ($d['source_type'] ?? 'git');
        $name = (string) ($d['name'] ?? '');
        $source = (array) ($d['source'] ?? []);

        // 1 · Source
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name) || strlen($name) > 63) {
            $add('name', 'source', 'fail', 'Name', 'Use lowercase letters, numbers and dashes, up to 63 characters.');
        } elseif (Application::withTrashed()->where('name', $name)->exists()) {
            $add('name', 'source', 'fail', 'Name', "“{$name}” is already used. Pick another name.");
        } else {
            $add('name', 'source', 'pass', 'Name', "“{$name}” is available.");
        }
        if ($type === 'image') {
            $image = trim((string) ($d['image'] ?? ''));
            if ($image === '') {
                $add('image', 'source', 'fail', 'Docker image', 'Enter an image such as nginx:1.27 or ghcr.io/acme/app:latest.');
            } else {
                $auth = $this->registryAuthFor($image);
                $r = $this->validator->image($image, $auth);
                $add('image', 'source', $r['ok'] ? 'pass' : 'fail', 'Docker image', $r['ok'] ? "{$image} is available".($auth ? ' with the saved registry credentials.' : '.') : $r['message']);
            }
        } else {
            $repo = trim((string) ($source['repo'] ?? ''));
            $branch = trim((string) ($source['branch'] ?? '')) ?: 'main';
            if ($repo === '') {
                $add('repo', 'source', 'fail', 'Repository', 'Enter the repository URL.');
            } else {
                $r = $this->validator->git($this->withToken($repo, $source['git_token'] ?? null), $branch);
                $add('repo', 'source', $r['ok'] ? 'pass' : 'fail', 'Repository', $r['ok']
                    ? "Branch {$branch} is readable".(! empty($r['commit']) ? ' (latest commit '.substr($r['commit'], 0, 7).').' : '.')
                    : $r['message'].(empty($source['git_token']) ? ' If the repository is private, add an access token.' : ' Check the access token.'));
                if ($r['ok'] && ! empty($r['commit'])) {
                    $detected['commit'] = $r['commit'];
                }
            }
        }

        // 2 · Build
        if (in_array($type, ['git', 'dockerfile'], true)) {
            $builder = $source['builder'] ?? 'dockerfile';
            $add('builder', 'build', 'pass', 'Builder', $builder === 'auto'
                ? 'The build detects Node.js, Python, Go, PHP or a static site when there is no Dockerfile.'
                : 'Builds from '.($source['dockerfile_path'] ?? 'Dockerfile').' in '.(($source['context'] ?? '.') ?: '.').'.');
            if (! empty($source['push_registry_id'])) {
                $reg = Registry::find($source['push_registry_id']);
                $add('registry', 'build', $reg ? ($reg->has_credentials ? 'pass' : 'warn') : 'fail', 'Registry', ! $reg
                    ? 'The chosen registry no longer exists.'
                    : ($reg->has_credentials ? "Images are pushed to {$reg->name}." : "{$reg->name} has no credentials saved; pushing may be refused."));
            } elseif (empty($d['server_id'])) {
                $add('registry', 'build', 'warn', 'Registry', 'Without a registry the image stays on the server that builds it, so that server also runs the app.');
            }
        }

        // 3 · Deploy
        $port = (int) ($d['port'] ?? 0);
        if (! empty($d['port']) && ($port < 1 || $port > 65535)) {
            $add('port', 'deploy', 'fail', 'Port', 'The port must be between 1 and 65535.');
        }
        if (! empty($d['server_id'])) {
            $server = Server::find($d['server_id']);
            if (! $server) {
                $add('server', 'deploy', 'fail', 'Server', 'The chosen server no longer exists.');
            } elseif ($server->maintenance) {
                $add('server', 'deploy', 'fail', 'Server', "{$server->name} is in maintenance mode; deployments are blocked.");
            } else {
                $add('server', 'deploy', $server->isOnline() ? 'pass' : 'fail', 'Server', $server->isOnline() ? "{$server->name} is online." : "{$server->name} is {$server->status}; it must be online.");
                if ($server->disk_pct !== null && $server->disk_pct > 80) {
                    $add('disk', 'deploy', $server->disk_pct > 90 ? 'fail' : 'warn', 'Disk', round($server->disk_pct).'% of the disk on '.$server->name.' is used.');
                }
            }
        } else {
            $probe = new Application(['size' => $d['size'] ?? 'standard', 'region' => $d['region'] ?? null]);
            $probe->organization_id = Tenant::current()?->id;
            try {
                $server = $this->scheduler->place($probe, $this->scheduler->ramFor($probe));
                $detected['server'] = ['id' => $server->id, 'name' => $server->name];
                $add('server', 'deploy', 'pass', 'Server', "Placed automatically on {$server->name}, the server with the most free memory and CPU.");
            } catch (ApiException $e) {
                $add('server', 'deploy', 'fail', 'Server', $e->getMessage());
            }
        }
        try {
            [$host, $platformHost] = $this->templates->resolveHost($d, $name ?: 'app');
            if (($d['domain_mode'] ?? null) === 'auto') {
                $host = ($name ?: 'app').'-xxxxxx.'.config('platform.domain');
            }
            if (! $host) {
                $add('domain', 'deploy', 'pass', 'Domain', 'No public address; the app is reachable only inside its network.');
            } elseif (! $port) {
                $add('domain', 'deploy', 'fail', 'Domain', 'A public address needs the port the app listens on.');
            } elseif ($platformHost) {
                $add('domain', 'deploy', 'pass', 'Domain', "{$host}: DNS, routing and HTTPS are set up for you.");
            } else {
                $zone = \App\Models\DnsZone::forDomain($host, Tenant::current()?->id);
                $add('domain', 'deploy', $zone ? 'pass' : 'warn', 'Domain', $zone
                    ? "{$host} is in your {$zone->name} DNS zone; its record and HTTPS are set up automatically."
                    : "{$host} is free. Point its A record at the server; HTTPS is issued once DNS resolves.");
            }
        } catch (ApiException $e) {
            $add('domain', 'deploy', 'fail', 'Domain', (string) (collect($e->fields ?? [])->flatten()->first() ?? $e->getMessage()));
        }

        $failed = count(array_filter($checks, fn ($c) => $c['status'] === 'fail'));

        return ['ok' => $failed === 0, 'failed' => $failed, 'warnings' => count(array_filter($checks, fn ($c) => $c['status'] === 'warn')),
            'checks' => $checks, 'detected' => $detected, 'platform_domain' => (string) config('platform.domain')];
    }

    /** Saved credentials for the image's registry host, if any (never returned to the browser). */
    public function registryAuthFor(string $image): ?array
    {
        [$host] = \App\Drivers\HttpSourceValidator::parse($image);
        $reg = Registry::all()->first(fn ($r) => BuildService::registryHost($r) === $host && $r->has_credentials);

        return $reg ? ['username' => $reg->username, 'password' => $reg->credentials] : null;
    }

    private function withToken(string $repo, ?string $token): string
    {
        return $token && str_starts_with($repo, 'https://') ? 'https://x-access-token:'.rawurlencode($token).'@'.substr($repo, 8) : $repo;
    }
}
