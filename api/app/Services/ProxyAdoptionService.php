<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Domain;
use App\Models\Job;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * Bringing hand-written Traefik routes under the panel (FW: live sites). The agent reads the
 * operator's own files in the file-provider directory; each file becomes one or more managed
 * domains with a fixed upstream (the container URL the file pointed at), so the firewall, WAF,
 * bouncer, public pages and uptime checks apply to them. The old file is retired only once the
 * managed route answers, and put back if it does not.
 */
class ProxyAdoptionService implements HandlesCommandResults
{
    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit) {}

    public function scan(Server $server, ?string $userId): Job
    {
        $this->online($server);
        $job = $this->jobs->create('proxy.adopt', ['execute'], $server, ['action' => 'scan', 'server_id' => $server->id], $userId, $server->organization_id);
        $this->bus->dispatch($server, 'proxy.scan', [], $job);

        return $job;
    }

    /** The last scan of a server, or null when it was never scanned. */
    public function scanned(Server $server): ?array
    {
        return Cache::get($this->key($server));
    }

    /** Turns the routers of one file into managed domains, pushes them, then retires the file. */
    public function adopt(Server $server, string $file, ?string $userId): Job
    {
        $this->online($server);
        if (! TraefikConfigService::enabled()) {
            throw ApiException::conflict('The panel does not manage Traefik here (CERT_DRIVER is not traefik), so it cannot take over these routes.');
        }
        $entry = collect($this->scanned($server)['files'] ?? [])->firstWhere('name', $file)
            ?? throw ApiException::validation(['file' => ['Scan the server first; this file was not found.']]);
        if (! $entry['adoptable']) {
            throw ApiException::conflict("{$file} cannot be adopted: {$entry['reason']}");
        }
        foreach ($entry['hosts'] as $host) {
            if (Domain::allTenants()->where('name', $host)->exists()) {
                throw ApiException::conflict("{$host} is already a domain on the platform.");
            }
        }
        $ids = [];
        foreach ($entry['hosts'] as $host) {
            $ids[] = Domain::create([
                'name' => $host, 'dns_status' => 'verified', 'https_status' => 'pending',
                'options' => ['upstream' => ['server_id' => $server->id, 'url' => $entry['upstream'], 'file' => $file, 'state' => 'adopting']],
            ])->id;
        }
        $this->audit->log('proxy.adopt', 'servers', $server->id, ['file' => $file, 'hosts' => $entry['hosts'], 'upstream' => $entry['upstream']], null, $userId);
        // The managed routers go out first; the agent runs commands in order, so the file is
        // retired only after Traefik has them.
        app(TraefikConfigService::class)->push(true);
        $job = $this->jobs->create('proxy.adopt', ['execute'], $server, ['action' => 'adopt', 'server_id' => $server->id, 'file' => $file, 'domain_ids' => $ids], $userId, $server->organization_id);
        $this->bus->dispatch($server, 'proxy.retire', ['file' => $file], $job);

        return $job;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $server = Server::allTenants()->find($job->payload['server_id'] ?? null);
        if ($command->status !== 'succeeded') {
            if ($command->type === 'proxy.retire') {
                $this->drop($job, 'restored');
            }
            $this->jobs->fail($job, (string) ($command->message ?: "{$command->type} failed"), 'execute');

            return;
        }
        match ($command->type) {
            'proxy.scan' => $this->saveScan($server, (array) $command->output, $job),
            'proxy.retire' => $this->verify($server, $job),
            'proxy.restore' => $this->jobs->fail($job, 'The adopted route did not answer; the hand-written file was put back.', 'execute'),
            default => $this->jobs->succeed($job),
        };
    }

    /**
     * Parses each file's routers: the hosts they match, and the one upstream URL they all send to.
     * A file is adoptable when every router with a service uses a file-provider service with a URL.
     */
    public static function parse(array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            $entry = ['name' => (string) $f['name'], 'hosts' => [], 'upstream' => null, 'routers' => [], 'adoptable' => false, 'reason' => null];
            try {
                $doc = Yaml::parse((string) $f['content']) ?? [];
            } catch (\Throwable $e) {
                $out[] = ['reason' => 'it is not valid YAML'] + $entry;

                continue;
            }
            $services = (array) data_get($doc, 'http.services', []);
            $urls = [];
            foreach ((array) data_get($doc, 'http.routers', []) as $name => $r) {
                preg_match_all('/Host\(([^)]*)\)/', (string) ($r['rule'] ?? ''), $m);
                $hosts = [];
                foreach ($m[1] as $args) {
                    preg_match_all('/`([^`]+)`/', $args, $h);
                    $hosts = array_merge($hosts, $h[1]);
                }
                $svc = (string) ($r['service'] ?? '');
                $url = str_contains($svc, '@') ? null : data_get($services, $svc.'.loadBalancer.servers.0.url');
                if ($svc !== '' && ! str_contains($svc, '@internal')) {
                    $urls[] = $url ?? '@'.$svc;
                }
                $entry['hosts'] = array_merge($entry['hosts'], $hosts);
                $entry['routers'][] = ['name' => (string) $name, 'hosts' => $hosts, 'service' => $svc, 'url' => $url, 'tls' => isset($r['tls'])];
            }
            $entry['hosts'] = array_values(array_unique(array_filter(array_map('strtolower', $entry['hosts']), fn ($h) => preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/', $h))));
            $urls = array_values(array_unique($urls));
            $entry['reason'] = match (true) {
                ! $entry['routers'] => 'it has no HTTP routers',
                ! $entry['hosts'] => 'its routers match no plain host names',
                count($urls) !== 1 => 'its routers send traffic to more than one place',
                str_starts_with($urls[0], '@') || ! preg_match('#^https?://[^\s/]+(:\d+)?/?$#', $urls[0]) => 'its service is not a single URL in this file',
                default => null,
            };
            $entry['adoptable'] = $entry['reason'] === null;
            $entry['upstream'] = $entry['adoptable'] ? rtrim($urls[0], '/') : null;
            $out[] = $entry;
        }

        return $out;
    }

    private function saveScan(?Server $server, array $out, Job $job): void
    {
        $files = self::parse((array) ($out['files'] ?? []));
        $result = ['files' => $files, 'adopted' => array_values((array) ($out['adopted'] ?? [])), 'scanned_at' => now()->toIso8601ZuluString()];
        if ($server) {
            Cache::put($this->key($server), $result, now()->addDay());
        }
        $this->jobs->succeed($job, ['files' => count($files), 'adoptable' => collect($files)->where('adoptable', true)->count()]);
    }

    /** The file is parked; the site must answer through the managed route, or it goes back. */
    private function verify(?Server $server, Job $job): void
    {
        $domains = Domain::allTenants()->whereIn('id', $job->payload['domain_ids'] ?? [])->get();
        $ok = $domains->isNotEmpty() && $domains->every(fn (Domain $d) => $this->answers($d->name));
        if ($ok) {
            foreach ($domains as $d) {
                $d->update(['options' => array_replace_recursive($d->options ?? [], ['upstream' => ['state' => 'active', 'adopted_at' => now()->toIso8601ZuluString()]])]);
            }
            $this->jobs->succeed($job, ['domains' => $domains->pluck('name')->all()]);

            return;
        }
        $this->drop($job, 'restored');
        if ($server) {
            $this->bus->dispatch($server, 'proxy.restore', ['file' => $job->payload['file']], $job);
        }
    }

    /** Takes the adopted domains out of the managed config again (kept, marked, for the record). */
    private function drop(Job $job, string $state): void
    {
        foreach (Domain::allTenants()->whereIn('id', $job->payload['domain_ids'] ?? [])->get() as $d) {
            $d->update(['options' => array_replace_recursive($d->options ?? [], ['upstream' => ['state' => $state]]), 'dns_status' => 'pending']);
        }
        app(TraefikConfigService::class)->push(true);
    }

    /** Anything below 500 counts: the site answered (a login redirect or a 404 page is still the site). */
    private function answers(string $host): bool
    {
        for ($i = 0; $i < 3; $i++) {
            try {
                if (Http::timeout(10)->withOptions(['allow_redirects' => false])->get("https://{$host}/")->status() < 500) {
                    return true;
                }
            } catch (\Throwable) {
            }
            $i < 2 && ! app()->runningUnitTests() && sleep(5);
        }

        return false;
    }

    private function online(Server $server): void
    {
        if (! $server->isOnline()) {
            throw ApiException::conflict('The server is offline.');
        }
    }

    private function key(Server $server): string
    {
        return 'proxy-adopt:'.$server->id;
    }
}
