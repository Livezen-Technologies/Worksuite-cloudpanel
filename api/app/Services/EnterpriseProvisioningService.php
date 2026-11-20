<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Container;
use App\Models\Database;
use App\Models\Domain;
use App\Models\EnterpriseDeployment;
use App\Models\EnterpriseWebhook;
use App\Models\EnvVar;
use App\Models\Job;
use App\Models\Project;
use App\Models\Secret;
use App\Models\Server;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enterprise provisioning (docs/ENTERPRISE_API.md). A deployment is a project
 * "Enterprise · <customer>" holding a managed MariaDB, a managed Redis, the
 * WorkSuite Server app (public, <slug>.<enterprise domain>, TLS) and its worker,
 * all on one node. It is built from the same database, deployment, domain and
 * container services the panel uses everywhere else; this class only orders
 * them (a step machine advanced by `enterprise:sync`) and reports to Cloud Admin.
 */
class EnterpriseProvisioningService implements HandlesCommandResults
{
    public const ACTIONS = ['restart', 'stop', 'start', 'redeploy'];

    private ?Application $pendingRedeploy = null;

    public const STEPS = [
        'placement' => 'Choose a node',
        'image' => 'WorkSuite Server image on the node',
        'database' => 'MariaDB',
        'redis' => 'Redis',
        'app' => 'WorkSuite Server',
        'worker' => 'Queue worker',
        'domain' => 'Address and TLS',
        'setup' => 'Waiting for the setup wizard',
    ];

    public function __construct(
        private Tenant $tenant,
        private DatabaseService $databases,
        private DeploymentService $deployments,
        private DomainService $domains,
        private ContainerService $containers,
        private CommandBus $bus,
        private JobService $jobs,
        private Scheduler $scheduler,
        private AuditLogger $audit,
    ) {}

    public static function hostFor(string $slug): string
    {
        return $slug.'.'.config('enterprise.domain');
    }

    public static function image(string $version): string
    {
        return config('enterprise.image').':'.$version;
    }

    public function slugAvailable(string $slug, ?string $ignoreId = null): bool
    {
        if (in_array($slug, (array) config('enterprise.reserved_slugs'), true)) {
            return false;
        }
        if (EnterpriseDeployment::where('slug', $slug)->whereNull('purged_at')->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            return false;
        }

        if (Domain::allTenants()->where('name', self::hostFor($slug))->exists()) {
            return false;
        }

        return ! $this->zoneHasName(self::hostFor($slug));
    }

    /** The enterprise domain's managed DNS zone (any organisation; worksuite.lk lives in the staff org). */
    public function enterpriseZone(): ?\App\Models\DnsZone
    {
        return \App\Models\DnsZone::allTenants()->where('name', (string) config('enterprise.domain'))->first();
    }

    /** True when the zone already carries a record at that name or below it (app, demo, desk…). */
    public function zoneHasName(string $host): bool
    {
        $zone = $this->enterpriseZone();

        return $zone && $zone->records()->withoutGlobalScope('organization')
            ->where(fn ($q) => $q->where('name', $host)->orWhere('name', 'like', '%.'.addcslashes($host, '%_\\')))->exists();
    }

    /** Publish results summarised for a step message: "DNS: A acme.worksuite.lk created; …". */
    public static function zoneSummary(array $published): ?string
    {
        if (! $published) {
            return null;
        }

        return 'DNS: '.collect($published)->map(fn ($r) => $r['type'].' '.$r['name'].' '.$r['status'].(in_array($r['status'], ['conflict', 'failed'], true) && ! empty($r['message']) ? ' ('.$r['message'].')' : ''))->implode('; ');
    }

    /** Keep the zone publish result on the step, so later checks still show it. */
    private function stepDns(EnterpriseDeployment $dep, string $name, array $published): void
    {
        $steps = $dep->steps ?? [];
        foreach ($steps as &$st) {
            if ($st['name'] === $name) {
                $st['dns'] = $published;
            }
        }
        unset($st);
        $dep->update(['steps' => $steps]);
    }

    /** Validate, record and start a deployment. Repeating the same external_id returns the existing one. */
    public function create(User $user, array $in): array
    {
        if ($existing = EnterpriseDeployment::where('external_id', $in['external_id'])->first()) {
            return [$existing, null, false];
        }
        if (! $this->slugAvailable($in['slug'])) {
            throw ApiException::validation(['slug' => [self::hostFor($in['slug']).' is taken or reserved. Pick another slug.']]);
        }
        $orgId = $this->tenant->id() ?? $user->current_organization_id;
        $size = config('enterprise.sizes')[$in['size']];
        $server = $this->place($orgId, $size, $in['node_id'] ?? null, $in['region'] ?? null);
        $secret = ! empty($in['callback_secret']) ? (string) $in['callback_secret'] : Str::random(48);

        $dep = DB::transaction(function () use ($in, $orgId, $server, $secret) {
            $project = Project::create([
                'organization_id' => $orgId,
                'name' => Str::limit('Enterprise · '.$in['customer_name'], 120, ''),
                'description' => 'WorkSuite Server for '.$in['customer_name'].' ('.self::hostFor($in['slug']).'), managed by Cloud Admin.',
                'color' => 'purple', 'tags' => ['enterprise'], 'webhook_secret' => Str::random(40),
            ]);
            $dep = EnterpriseDeployment::create([
                'organization_id' => $orgId, 'project_id' => $project->id, 'server_id' => $server->id,
                'external_id' => $in['external_id'], 'customer_name' => $in['customer_name'], 'slug' => $in['slug'],
                'version' => $in['version'], 'size' => $in['size'], 'status' => 'queued',
                'hostname' => self::hostFor($in['slug']), 'callback_url' => $in['callback_url'] ?? null, 'callback_secret' => $secret,
                'steps' => collect(self::STEPS)->map(fn ($label, $name) => ['name' => $name, 'label' => $label, 'status' => 'pending', 'started_at' => null, 'finished_at' => null, 'message' => null])->values()->all(),
            ]);
            $this->step($dep, 'placement', 'succeeded', "Node {$server->name}".($server->region ? " ({$server->region})" : ''));

            return $dep;
        });
        $this->log('enterprise.deployment.create', $dep, ['slug' => $dep->slug, 'version' => $dep->version, 'size' => $dep->size, 'server' => $server->name]);
        $this->setStatus($dep, 'provisioning');
        try {
            $this->advance($dep);
        } catch (\Throwable $e) {
            report($e);
        }

        return [$dep->fresh(), $secret, true];
    }

    /** The least-loaded eligible node: tagged nodes first (config enterprise.node_tag), else any app node. */
    public function place(string $orgId, array $size, ?string $nodeId, ?string $region): Server
    {
        $ram = (int) ($size['app_ram_mb'] + $size['worker_ram_mb'] + $size['db_ram_mb'] + $size['redis_ram_mb']);
        $cpu = (float) ($size['app_cpus'] + $size['worker_cpus']);
        $candidates = $this->candidateNodes($orgId);
        if ($nodeId) {
            $candidates = $candidates->where('id', $nodeId);
            if ($candidates->isEmpty()) {
                throw ApiException::validation(['node_id' => ['Unknown node, or it does not take workloads.']]);
            }
        }
        if ($region) {
            $candidates = $candidates->where('region', $region);
        }
        if (! $nodeId) {
            $candidates = $this->preferTagged($candidates);
        }
        $scored = [];
        $rejected = [];
        foreach ($candidates as $s) {
            $c = $this->scheduler->capacity($s);
            if ($why = $this->scheduler->ineligible($s, $c, $ram, $cpu, (bool) $nodeId)) {
                $rejected[] = "{$s->name}: {$why}";

                continue;
            }
            $scored[] = ['s' => $s, 'score' => ($c['free_ram_mb'] - $ram) / max(1, $c['allocatable_ram_mb']) + ($c['free_cpu'] - $cpu) / max(0.1, $c['allocatable_cpu'])];
        }
        if (! $scored) {
            throw ApiException::conflict('No node can take this deployment right now ('.($rejected ? implode('; ', array_slice($rejected, 0, 6)) : 'no nodes').').', ['reason' => 'no_capacity', 'nodes' => $rejected]);
        }

        return collect($scored)->sortByDesc('score')->first()['s'];
    }

    /** App nodes the organisation may place on (its own and the shared pool). */
    public function candidateNodes(string $orgId)
    {
        return Server::allTenants()
            ->where(fn ($q) => $q->where('organization_id', $orgId)->orWhere('shared_pool', true))
            ->get()->filter(fn (Server $s) => $s->hasRole('app'))->values();
    }

    /** Nodes tagged config enterprise.node_tag when there are any, else all of them. */
    public function preferTagged($candidates)
    {
        $tag = (string) config('enterprise.node_tag');
        $tagged = $candidates->filter(fn (Server $s) => in_array($tag, (array) ($s->tags ?? []), true))->values();

        return $tagged->isNotEmpty() ? $tagged : $candidates;
    }

    /** The deployment's effective size: custom limits after a resize, else its preset. */
    public function sizeOf(EnterpriseDeployment $dep): array
    {
        return $dep->limits ?: (config('enterprise.sizes')[$dep->size] ?? config('enterprise.sizes.small'));
    }

    /** One step of the machine: start what is due, record what finished. Safe to call repeatedly. */
    public function advance(EnterpriseDeployment $dep): void
    {
        if (! in_array($dep->status, ['provisioning', 'configuring', 'active'], true)) {
            return;
        }
        $this->tenant->as($dep->organization_id, function () use ($dep) {
            $size = $this->sizeOf($dep);
            $base = 'ent-'.$dep->slug;
            if ($dep->status === 'provisioning') {
                if (! $dep->database_id) {
                    $db = $this->databases->create([
                        'name' => $base.'-db', 'engine' => config('enterprise.database.engine'), 'version' => config('enterprise.database.version'),
                        'server_id' => $dep->server_id, 'any_tenant_server' => true, 'project_id' => $dep->project_id,
                        'database' => config('enterprise.database.name'), 'memory_mb' => $size['db_ram_mb'],
                    ]);
                    $dep->update(['database_id' => $db['database']['id']]);
                    $this->step($dep, 'database', 'running', 'Creating MariaDB '.config('enterprise.database.version'));
                }
                if (! $dep->redis_id) {
                    $r = $this->databases->create([
                        'name' => $base.'-redis', 'engine' => config('enterprise.redis.engine'), 'version' => config('enterprise.redis.version'),
                        'server_id' => $dep->server_id, 'any_tenant_server' => true, 'project_id' => $dep->project_id, 'memory_mb' => $size['redis_ram_mb'],
                    ]);
                    $dep->update(['redis_id' => $r['database']['id']]);
                    $this->step($dep, 'redis', 'running', 'Creating Redis');
                }
                $db = Database::allTenants()->find($dep->database_id);
                $redis = Database::allTenants()->find($dep->redis_id);
                foreach (['database' => $db, 'redis' => $redis] as $step => $row) {
                    if ($row?->status === 'running') {
                        $this->step($dep, $step, 'succeeded', 'Running');
                    } elseif (! $row || $row->status === 'failed') {
                        $this->fail($dep, $step, ucfirst($step).' container failed to start.');

                        return;
                    }
                }
                if ($db?->status !== 'running' || $redis?->status !== 'running') {
                    return;
                }
                if (! $dep->app_application_id) {
                    if (! $this->imageReady($dep)) {
                        return;
                    }
                    $this->createApps($dep, $db, $redis, $size);
                }
                foreach (['app' => $dep->app_application_id, 'worker' => $dep->worker_application_id] as $step => $id) {
                    $app = Application::allTenants()->find($id);
                    $last = $app ? \App\Models\Deployment::where('application_id', $app->id)->latest()->first() : null;
                    if ($app?->status === 'running') {
                        $this->step($dep, $step, 'succeeded', 'Running '.self::image($dep->version));
                    } elseif (! $app || $app->status === 'failed' || in_array($last?->status, ['failed', 'rolled_back'], true)) {
                        $this->fail($dep, $step, Str::limit((string) (Job::find($last?->job_id)?->error ?? 'The container did not become healthy.'), 250));

                        return;
                    }
                }
                $this->checkDomain($dep, $dep->hostname, 'domain');
                if ($this->stepStatus($dep, 'app') === 'succeeded' && $this->stepStatus($dep, 'worker') === 'succeeded') {
                    $this->step($dep, 'setup', 'running', 'Ready for the setup wizard');
                    $this->setStatus($dep, 'awaiting_setup');
                }

                return;
            }
            // configuring / active: the address and the optional custom domain keep being checked.
            $this->checkDomain($dep, $dep->hostname, 'domain');
            if ($dep->custom_domain) {
                $this->checkDomain($dep, $dep->custom_domain, 'custom_domain');
            }
            if ($dep->status === 'active' && (! $dep->status_checked_at || $dep->status_checked_at->lt(now()->subMinutes((int) config('enterprise.status_every_minutes'))))) {
                $this->exec($dep, 'status', (string) config('enterprise.status_command'));
            }
            if ($this->stepStatus($dep, 'redeploy') === 'running') {
                $apps = Application::allTenants()->whereIn('id', [$dep->app_application_id, $dep->worker_application_id])->get();
                if ($apps->every(fn ($a) => $a->status === 'running')) {
                    $this->step($dep, 'redeploy', 'succeeded', 'Running '.self::image($dep->version));
                } elseif ($apps->contains(fn ($a) => $a->status === 'failed')) {
                    $this->step($dep, 'redeploy', 'failed', 'A container did not become healthy after the redeploy.');
                }
            }
        });
    }

    /**
     * No registry: the image is `docker load`ed onto the node (LHCP docker/enterprise/build.sh --save),
     * so the deploy waits until the node's agent reports worksuite-server:<version>.
     */
    public function imageReady(EnterpriseDeployment $dep): bool
    {
        if (! config('enterprise.require_loaded_image')) {
            $this->step($dep, 'image', 'succeeded', self::image($dep->version).' (pulled on deploy)');

            return true;
        }
        $ref = self::image($dep->version);
        $repo = (string) config('enterprise.image');
        $present = \App\Models\Image::allTenants()->where('server_id', $dep->server_id)->get(['repository', 'tags'])
            ->contains(fn ($i) => in_array($ref, (array) ($i->tags ?? []), true) || ($i->repository === $repo && in_array($dep->version, (array) ($i->tags ?? []), true)));
        $server = Server::allTenants()->find($dep->server_id);
        if (! $present && $server) {
            // The heartbeat inventory can lag on a busy node: ask the node directly too.
            $present = in_array($ref, $this->scannedTags($server), true);
            $present || $this->scanImages($server, 120);
        }
        $node = $server?->name ?? 'the node';
        $this->step($dep, 'image', $present ? 'succeeded' : 'running', $present ? "{$ref} is on {$node}" : "Waiting for {$ref} on {$node}: load it with docker load (build.sh --save)");

        return $present;
    }

    private function createApps(EnterpriseDeployment $dep, Database $db, Database $redis, array $size): void
    {
        $env = config('enterprise.env');
        $dbPass = Secret::allTenants()->find($db->secret_id)?->value;
        $redisPass = Secret::allTenants()->find($redis->secret_id)?->value;
        $vars = [
            [$env['base_url'], 'https://'.$dep->hostname, false],
            [$env['db_host'], 'db-'.Str::slug($db->name), false],
            [$env['db_port'], (string) config('enterprise.database.port'), false],
            [$env['db_name'], (string) config('enterprise.database.name'), false],
            [$env['db_user'], (string) config('enterprise.database.user'), false],
            [$env['db_password'], (string) $dbPass, true],
            [$env['redis_host'], 'db-'.Str::slug($redis->name), false],
            [$env['redis_port'], (string) config('enterprise.redis.port'), false],
            [$env['redis_password'], (string) $redisPass, true],
            [$env['fpm_max_children'], (string) ($size['fpm_max_children'] ?? 10), false],
            [$env['deployment_id'], $dep->id, false],
        ];
        $mounts = collect(config('enterprise.volumes'))->map(fn ($target, $suffix) => ['source' => 'ent-'.$dep->slug.'-'.$suffix, 'target' => $target])->values()->all();
        // Cloud Admin is the approver: no second-person approval on these deploys.
        $environment = \App\Models\Environment::where('project_id', $dep->project_id)->where('kind', 'production')->first()
            ?? app(EnvironmentService::class)->createEnvironment(Project::findOrFail($dep->project_id), ['name' => 'Production', 'kind' => 'production', 'requires_approval' => false]);
        $service = User::find(auth()->id()) ?? User::whereHas('memberships', fn ($q) => $q->where('organization_id', $dep->organization_id))->first();

        foreach (['app' => true, 'worker' => false] as $role => $public) {
            $app = Application::create([
                'name' => 'ent-'.$dep->slug.($public ? '' : '-worker'), 'kind' => 'customer', 'source_type' => 'image',
                'image' => self::image($dep->version), 'strategy' => 'recreate',
                'project_id' => $dep->project_id, 'environment_id' => $environment->id, 'environment' => 'production',
                'server_id' => $dep->server_id, 'size' => 'small', 'status' => 'deploying',
                'port' => $public ? (int) config('enterprise.port') : null,
                'health_path' => $public ? (string) config('enterprise.health_path') : null,
                'memory_limit_mb' => $size[$role.'_ram_mb'], 'cpu_limit' => $size[$role.'_cpus'],
                'source' => array_filter([
                    'image' => self::image($dep->version), 'database_id' => $db->id, 'enterprise_deployment_id' => $dep->id,
                    'cmd' => config('enterprise.'.$role.'_cmd'), 'mounts' => $mounts, 'pull' => config('enterprise.pull'),
                    // The entrypoint starts as root to prepare the uid-33 volumes: it needs these back from cap_drop ALL.
                    'cap_add' => array_values((array) config('enterprise.cap_add')),
                ]),
            ]);
            foreach ($vars as [$k, $v, $secret]) {
                EnvVar::create(['application_id' => $app->id, 'key' => $k, 'value' => $v, 'secret' => $secret]);
            }
            if ($public) {
                $platform = config('enterprise.domain') === config('platform.domain');
                $domain = $this->domains->create(['name' => $dep->hostname, 'application_id' => $app->id, 'target_port' => $app->port, 'platform_subdomain' => $platform, 'is_primary' => true], $platform);
                $published = (array) ($domain->publishedRecords ?? []);
                $dep->update(['app_application_id' => $app->id]);
            } else {
                $dep->update(['worker_application_id' => $app->id]);
            }
            $this->deployments->start($app, $service, 'manual');
            $this->step($dep, $role, 'running', 'Deploying '.self::image($dep->version));
        }
        $published ??= [];
        $this->step($dep, 'domain', collect($published)->contains(fn ($r) => in_array($r['status'], ['conflict', 'failed'], true)) ? 'failed' : 'running',
            trim($dep->hostname.' '.(self::zoneSummary($published) ?? '')));
        $this->stepDns($dep, 'domain', $published);
    }

    /** DNS → certificate for one of the deployment's domains; the step follows it. */
    private function checkDomain(EnterpriseDeployment $dep, ?string $name, string $step): void
    {
        $domain = $name ? Domain::allTenants()->where('name', $name)->first() : null;
        if (! $domain) {
            return;
        }
        if ($domain->dns_status !== 'verified' || $domain->https_status !== 'active') {
            rescue(fn () => $this->domains->verify($domain), report: false);
            $domain->refresh();
        }
        $done = $domain->dns_status === 'verified' && $domain->https_status === 'active';
        $published = (array) (collect($dep->steps ?? [])->firstWhere('name', $step)['dns'] ?? []);
        $conflict = collect($published)->contains(fn ($r) => in_array($r['status'], ['conflict', 'failed'], true));
        $zone = self::zoneSummary($published);
        $this->step($dep, $step, $done ? 'succeeded' : ($conflict ? 'failed' : 'running'), $done ? "https://{$domain->name} is live" : "DNS {$domain->dns_status}, TLS ".($domain->https_status ?? 'pending').($zone ? '. '.$zone : ''));
    }

    /** Runs the wizard's bootstrap inside the app container; attaches the customer's own domain when given. */
    public function configure(EnterpriseDeployment $dep, array $bootstrap, ?string $customDomain): EnterpriseDeployment
    {
        if (! in_array($dep->status, ['awaiting_setup', 'failed'], true) || ($dep->status === 'failed' && $this->stepStatus($dep, 'bootstrap') !== 'failed')) {
            throw ApiException::conflict("The deployment is {$dep->status}; it can be configured once it is awaiting setup.");
        }
        $this->tenant->as($dep->organization_id, function () use ($dep, $bootstrap, $customDomain) {
            if ($customDomain && $customDomain !== $dep->custom_domain) {
                $app = Application::findOrFail($dep->app_application_id);
                try {
                    $published = (array) ($this->domains->create(['name' => $customDomain, 'application_id' => $app->id, 'target_port' => $app->port])->publishedRecords ?? []);
                } catch (ApiException $e) {
                    throw ApiException::validation(['custom_domain' => [$e->getMessage()]]);
                }
                $dep->update(['custom_domain' => $customDomain]);
                // The image takes its address from APP_URL: point it at the customer's domain and redeploy web.
                EnvVar::where('application_id', $app->id)->where('key', config('enterprise.env.base_url'))->get()->each->update(['value' => 'https://'.$customDomain]);
                $this->pendingRedeploy = $app;
                $this->step($dep, 'custom_domain', 'running', trim('Waiting for DNS: '.$customDomain.' '.(self::zoneSummary($published) ?? '')), 'Your own domain');
                $this->stepDns($dep, 'custom_domain', $published);
            }
            $json = json_encode((object) $bootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $command = 'cd '.escapeshellarg((string) config('enterprise.workdir')).' && printf %s '.base64_encode($json).' | base64 -d | '.config('enterprise.bootstrap_command');
            if (strlen($command) > 4000) {
                throw ApiException::validation(['bootstrap' => ['The bootstrap settings are too large.']]);
            }
            $this->step($dep, 'setup', 'succeeded', 'Setup wizard finished');
            $this->step($dep, 'bootstrap', 'running', 'Running enterprise:bootstrap', 'Configure WorkSuite');
            $this->setStatus($dep, 'configuring');
            $this->exec($dep, 'bootstrap', $command);
        });
        // After the bootstrap command is queued, so it runs in the container that is up now.
        if ($this->pendingRedeploy) {
            $this->tenant->as($dep->organization_id, fn () => $this->deployments->start($this->pendingRedeploy->fresh(), User::find(auth()->id()), 'redeploy'));
            $this->pendingRedeploy = null;
        }
        $this->log('enterprise.deployment.configure', $dep, ['custom_domain' => $customDomain]);

        return $dep->fresh();
    }

    /** `docker exec` in the running app container through the agent's cron.exec. */
    private function exec(EnterpriseDeployment $dep, string $kind, string $command): Job
    {
        $app = Application::allTenants()->findOrFail($dep->app_application_id);
        $container = Container::allTenants()->where('application_id', $app->id)->where('state', 'running')->first();
        $server = Server::allTenants()->findOrFail($dep->server_id);
        $job = $this->jobs->create('enterprise.exec', ['execute'], $dep, ['kind' => $kind, 'deployment_id' => $dep->id], null, $dep->organization_id);
        $this->bus->dispatch($server, 'cron.exec', [
            'mode' => 'container', 'container' => $container?->name ?? $app->containerName(), 'user' => (string) config('enterprise.exec_user'),
            'command' => $command, 'timeout' => (int) config('enterprise.exec_timeout'),
        ], $job);
        $kind === 'licence' || $dep->update([$kind === 'bootstrap' ? 'bootstrap_job_id' : 'status_job_id' => $job->id] + ($kind === 'status' ? ['status_checked_at' => now()] : []));

        return $job;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        // The command carried the administrator's password: keep only that it ran.
        $payload = (array) $command->payload;
        $payload['command'] = '[redacted]';
        if ($row = AgentCommand::allTenants()->find($command->id)) {
            $row->payload = $payload;
            $row->saveQuietly();
        }

        $out = (array) ($command->output ?? []);
        if (($job->payload['kind'] ?? null) === 'images') {
            $this->imagesScanned($command, $job, $out);

            return;
        }
        $dep = EnterpriseDeployment::find($job->payload['deployment_id'] ?? null);
        $text = (string) ($out['output'] ?? '');
        $result = self::lastJson($text);
        $exit = $out['exit_code'] ?? ($command->status === 'succeeded' ? 0 : null);
        $kind = $job->payload['kind'] ?? null;
        $codes = (array) config('enterprise.bootstrap_exit_codes');
        $licenceFailed = $kind === 'bootstrap' && $exit !== null && in_array((int) $exit, (array) ($codes['licence_failed'] ?? []), true);
        $ok = $exit !== null && ($kind === 'bootstrap' ? in_array((int) $exit, (array) ($codes['ok'] ?? [0]), true) || $licenceFailed : (int) $exit === 0);
        $ok ? $this->jobs->succeed($job, ['exit_code' => $exit]) : $this->jobs->fail($job, Str::limit($this->redact($dep, $result['error'] ?? $command->message ?? $text ?: 'exit '.$exit), 250));
        if (! $dep) {
            return;
        }
        if (($job->payload['kind'] ?? null) === 'status') {
            $result !== null && $dep->update(['app_status' => $result]);

            return;
        }
        if (($job->payload['kind'] ?? null) === 'licence') {
            $this->step($dep, 'licence_refresh', $ok ? 'succeeded' : 'failed', $ok ? 'Licence refreshed' : Str::limit($this->redact($dep, (string) ($result['error'] ?? $command->message ?? 'license:refresh failed (exit '.$exit.')')), 250), 'Licence refresh');
            if ($ok && $this->stepStatus($dep, 'licence') === 'failed') {
                $this->step($dep, 'licence', 'succeeded', 'Licence active', 'Licence');
            }
            $ok && $this->exec($dep->fresh(), 'status', (string) config('enterprise.status_command'));

            return;
        }
        $dep->update(['bootstrap_result' => $result ? array_diff_key($result, array_flip(['password', 'set_password_url', 'token'])) : ['exit_code' => $exit]]);
        if ($ok) {
            $this->step($dep, 'bootstrap', 'succeeded', (int) $exit === 3 ? 'WorkSuite was already configured' : 'WorkSuite configured');
            if ($licenceFailed) {
                // Set up and usable; the licence is retried with `license:activate`.
                $this->step($dep, 'licence', 'failed', Str::limit($this->redact($dep, (string) ($result['error'] ?? 'Licence activation failed; retry license:activate.')), 250), 'Licence');
            }
            $dep->update(['activated_at' => now()]);
            $this->step($dep, 'live', 'succeeded', 'https://'.($dep->custom_domain ?: $dep->hostname), 'Live');
            $this->setStatus($dep, 'active');
            $this->exec($dep->fresh(), 'status', (string) config('enterprise.status_command'));
        } else {
            $this->fail($dep, 'bootstrap', Str::limit($this->redact($dep, (string) ($result['error'] ?? $command->message ?? 'enterprise:bootstrap failed (exit '.$exit.')')), 250));
        }
    }

    /** The last JSON object a command printed (spark may print warnings first). */
    public static function lastJson(string $text): ?array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        foreach (array_reverse(explode("\n", $text)) as $line) {
            $d = json_decode(trim($line), true);
            if (is_array($d)) {
                return $d;
            }
        }
        $start = strpos($text, '{');

        return $start !== false && is_array($d = json_decode(substr($text, $start), true)) ? $d : null;
    }

    public function act(EnterpriseDeployment $dep, string $action, array $params = []): array
    {
        if (in_array($dep->status, ['deleted', 'purged', 'queued'], true) || ! $dep->app_application_id) {
            throw ApiException::conflict("The deployment is {$dep->status}; {$action} is not possible.");
        }

        return $this->tenant->as($dep->organization_id, function () use ($dep, $action, $params) {
            $jobs = [];
            if ($action === 'redeploy') {
                if (! empty($params['version'])) {
                    $dep->update(['version' => $params['version']]);
                }
                foreach ([$dep->app_application_id, $dep->worker_application_id] as $id) {
                    $app = Application::findOrFail($id);
                    $app->update(['image' => self::image($dep->version), 'source' => array_merge((array) $app->source, ['image' => self::image($dep->version)])]);
                    $jobs[] = $this->deployments->start($app->fresh(), User::find(auth()->id()), 'redeploy')->job_id;
                }
                $this->step($dep, 'redeploy', 'running', 'Redeploying '.self::image($dep->version), 'Redeploy');
                $this->reviveAfterRedeploy($dep);
            } else {
                $roles = $action === 'restart' ? (! empty($params['container']) ? [$params['container']] : ['app', 'worker']) : ['database', 'redis', 'app', 'worker'];
                $containers = $this->containersOf($dep);
                if ($action === 'stop') {
                    $containers = array_reverse($containers, true);
                }
                foreach ($containers as $role => $c) {
                    if ($c && in_array($role, $roles, true)) {
                        $jobs[] = $this->containers->perform($c, $action)->id;
                    }
                }
                if ($action === 'stop') {
                    $this->setStatus($dep, 'stopped');
                } elseif ($action === 'start' && $dep->status === 'stopped') {
                    $this->setStatus($dep, $dep->activated_at ? 'active' : ($this->stepStatus($dep, 'setup') === 'running' ? 'awaiting_setup' : 'provisioning'));
                }
            }
            $this->log("enterprise.deployment.{$action}", $dep, $params);

            return ['action' => $action, 'job_ids' => $jobs, 'status' => $dep->fresh()->status];
        });
    }

    /** DELETE: stop everything, keep every volume. Purge is a separate, explicit call. */
    public function delete(EnterpriseDeployment $dep): EnterpriseDeployment
    {
        if (in_array($dep->status, ['deleted', 'purged'], true)) {
            return $dep;
        }
        $this->tenant->as($dep->organization_id, function () use ($dep) {
            foreach (array_reverse($this->containersOf($dep), true) as $c) {
                $c && $this->containers->perform($c, 'stop');
            }
        });
        $dep->update(['deleted_at' => now()]);
        $this->setStatus($dep, 'deleted');
        $this->log('enterprise.deployment.delete', $dep, []);

        return $dep->fresh();
    }

    /** Irreversible: containers, volumes (data!), databases, domains and the project go. */
    public function purge(EnterpriseDeployment $dep): EnterpriseDeployment
    {
        if ($dep->status !== 'deleted') {
            throw ApiException::conflict('Delete the deployment first; purge removes the data of a deleted deployment only.');
        }
        $this->tenant->as($dep->organization_id, function () use ($dep) {
            $server = Server::allTenants()->find($dep->server_id);
            foreach ($this->containersOf($dep) as $c) {
                $c && $this->containers->perform($c, 'remove', ['keep_volumes' => false]);
            }
            if ($server) {
                $volumes = collect(config('enterprise.volumes'))->keys()->map(fn ($s) => 'ent-'.$dep->slug.'-'.$s)
                    ->merge(Database::whereIn('id', array_filter([$dep->database_id, $dep->redis_id]))->get()->map(fn ($d) => 'db-'.Str::slug($d->name).'-data'));
                foreach ($volumes as $v) {
                    $this->bus->dispatch($server, 'volume.remove', ['name' => $v, 'force' => true]);
                }
            }
            foreach (array_filter([$dep->app_application_id, $dep->worker_application_id]) as $id) {
                if ($app = Application::find($id)) {
                    Domain::where('application_id', $app->id)->get()->each->delete();
                    $app->update(['status' => 'deleted']);
                    $app->delete();
                }
            }
            Database::whereIn('id', array_filter([$dep->database_id, $dep->redis_id]))->get()->each(function (Database $d) {
                Secret::whereKey($d->secret_id)->delete();
                $d->delete();
            });
            Project::whereKey($dep->project_id)->delete();
        });
        $dep->update(['purged_at' => now()]);
        $this->setStatus($dep, 'purged');
        $this->log('enterprise.deployment.purge', $dep, []);

        return $dep->fresh();
    }

    /** @return array<string, ?Container> role => container */
    public function containersOf(EnterpriseDeployment $dep): array
    {
        $db = Database::allTenants()->whereIn('id', array_filter([$dep->database_id, $dep->redis_id]))->get()->keyBy('id');
        $byApp = fn ($id) => $id ? Container::allTenants()->where('application_id', $id)->orderByDesc('updated_at')->first() : null;

        return [
            'database' => ($c = $db[$dep->database_id]?->container_id ?? null) ? Container::allTenants()->find($c) : null,
            'redis' => ($c = $db[$dep->redis_id]?->container_id ?? null) ? Container::allTenants()->find($c) : null,
            'app' => $byApp($dep->app_application_id),
            'worker' => $byApp($dep->worker_application_id),
        ];
    }

    public function logs(EnterpriseDeployment $dep, string $role, int $tail): array
    {
        $c = $this->containersOf($dep)[$role] ?? null;
        if (! $c) {
            throw ApiException::conflict("The deployment has no {$role} container yet.");
        }
        $out = $this->tenant->as($dep->organization_id, fn () => $this->containers->logs($c, max(1, min(2000, $tail))));

        return ['container' => $role, 'name' => $c->name, 'lines' => array_map(fn ($l) => $this->redact($dep, (string) $l), $out['lines']), 'fetched_at' => $out['fetched_at']];
    }

    /** Known secret values and obvious credentials never leave in logs or errors. */
    public function redact(?EnterpriseDeployment $dep, string $text): string
    {
        $values = [];
        if ($dep) {
            foreach (array_filter([$dep->database_id, $dep->redis_id]) as $id) {
                $values[] = Secret::allTenants()->find(Database::allTenants()->find($id)?->secret_id)?->value;
            }
            foreach (EnvVar::whereIn('application_id', array_filter([$dep->app_application_id, $dep->worker_application_id]))->where('secret', true)->get() as $v) {
                $values[] = (string) $v->value;
            }
        }
        foreach (array_filter(array_unique($values), fn ($v) => is_string($v) && strlen($v) >= 6) as $v) {
            $text = str_replace($v, '[redacted]', $text);
        }

        return (string) preg_replace('/((?:password|passwd|secret|token|api[_-]?key|licen[cs]e[_-]?key)["\']?\s*[:=]\s*["\']?)[^\s"\',}]+/i', '$1[redacted]', $text);
    }

    public function present(EnterpriseDeployment $dep): array
    {
        $server = Server::allTenants()->find($dep->server_id);
        $containers = collect($this->containersOf($dep))->map(fn (?Container $c, $role) => $c ? [
            'role' => $role, 'name' => $c->name, 'state' => $c->state, 'health' => $c->health,
            'cpu_pct' => $c->cpu_pct, 'mem_mb' => $c->mem_mb, 'started_at' => $c->started_at?->toIso8601ZuluString(),
        ] : ['role' => $role, 'name' => null, 'state' => 'absent', 'health' => null, 'cpu_pct' => null, 'mem_mb' => null, 'started_at' => null])->values();
        $size = $this->sizeOf($dep);
        $domainView = function (?string $name) {
            $d = $name ? Domain::allTenants()->where('name', $name)->first() : null;

            return $d ? ['name' => $d->name, 'dns_status' => self::dnsState($d->dns_status), 'https_status' => self::tlsState($d->https_status),
                'dns_raw' => $d->dns_status, 'https_raw' => $d->https_status ?? 'pending',
                'records' => $d->platform_subdomain ? [] : rescue(fn () => $this->domains->expectedRecords($d), [], false)] : null;
        };
        $custom = $domainView($dep->custom_domain);
        $customLive = $custom && $custom['dns_status'] === 'ok' && $custom['https_status'] === 'ok';

        return [
            'id' => $dep->id,
            'external_id' => $dep->external_id,
            'customer_name' => $dep->customer_name,
            'slug' => $dep->slug,
            'version' => $dep->version,
            'image' => self::image($dep->version),
            'size' => $dep->size,
            'status' => $dep->status,
            'status_reason' => $dep->status_reason,
            'project_id' => $dep->project_id,
            'node' => $server ? ['id' => $server->id, 'name' => $server->name, 'region' => $server->region] : null,
            'urls' => [
                'app' => 'https://'.($customLive ? $dep->custom_domain : $dep->hostname),
                'platform' => 'https://'.$dep->hostname,
                'custom' => $dep->custom_domain ? 'https://'.$dep->custom_domain : null,
                'login' => 'https://'.($customLive ? $dep->custom_domain : $dep->hostname).'/login',
                'panel' => rtrim((string) config('platform.panel_url'), '/').'/admin/projects/'.$dep->project_id,
            ],
            'health' => $this->health($containers->all()),
            'domain' => $domainView($dep->hostname),
            'custom_domain' => $custom,
            'steps' => $dep->steps ?? [],
            'errors' => collect($dep->steps ?? [])->where('status', 'failed')->map(fn ($s) => ['step' => $s['name'], 'message' => $s['message'], 'at' => $s['finished_at']])->values(),
            'containers' => $containers,
            'resources' => [
                'cpu_pct' => round((float) $containers->sum('cpu_pct'), 1),
                'ram_mb' => (int) $containers->sum('mem_mb'),
                'ram_limit_mb' => (int) (($size['app_ram_mb'] ?? 0) + ($size['worker_ram_mb'] ?? 0) + ($size['db_ram_mb'] ?? 0) + ($size['redis_ram_mb'] ?? 0)),
                'cpu_limit' => (float) (($size['app_cpus'] ?? 0) + ($size['worker_cpus'] ?? 0)),
                'disk_gb' => $size['disk_gb'] ?? null,
                'node_disk_pct' => $server?->disk_pct,
            ],
            'limits' => [
                'size' => $dep->limits ? 'custom' : $dep->size,
                'cpus' => (float) ($size['app_cpus'] ?? 0), 'ram_mb' => (int) ($size['app_ram_mb'] ?? 0),
                'worker_cpus' => (float) ($size['worker_cpus'] ?? 0), 'worker_ram_mb' => (int) ($size['worker_ram_mb'] ?? 0),
                'disk_gb' => $size['disk_gb'] ?? null, 'fpm_max_children' => (int) ($size['fpm_max_children'] ?? 0),
            ],
            'login' => [
                'url' => 'https://'.($customLive ? $dep->custom_domain : $dep->hostname).'/login',
                'admin_email' => $dep->bootstrap_result['admin_email'] ?? $dep->bootstrap_result['administrator']['email'] ?? null,
            ],
            'domains' => $this->domainList($dep),
            'app_status' => $dep->app_status,
            'bootstrap' => $dep->bootstrap_result,
            'created_at' => $dep->created_at?->toIso8601ZuluString(),
            'updated_at' => $dep->updated_at?->toIso8601ZuluString(),
            'activated_at' => $dep->activated_at?->toIso8601ZuluString(),
            'deleted_at' => $dep->deleted_at?->toIso8601ZuluString(),
            'purged_at' => $dep->purged_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * ok: every container running and none unhealthy; down: the web container is not running;
     * degraded: anything in between.
     */
    public function health(array $containers): string
    {
        $by = collect($containers)->keyBy('role');
        if (($by['app']['state'] ?? null) !== 'running') {
            return 'down';
        }
        $ok = collect(['app', 'worker', 'database', 'redis'])->every(fn ($r) => ($by[$r]['state'] ?? null) === 'running' && ! in_array($by[$r]['health'] ?? null, ['unhealthy', 'starting'], true));

        return $ok ? 'ok' : 'degraded';
    }

    /** Periodic (`enterprise:sync`): advance every deployment that is still moving. */
    public function sync(): int
    {
        $n = 0;
        EnterpriseDeployment::whereIn('status', ['provisioning', 'configuring', 'active'])->chunkById(50, function ($deps) use (&$n) {
            foreach ($deps as $dep) {
                try {
                    $this->advance($dep);
                    $n++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        return $n;
    }

    // --- part E: versions, nodes, resize, metrics, domains, licence refresh, retry -----------

    /** Tags of the enterprise image the node reported through a direct `docker images` (cached). */
    public function scannedTags(Server $server): array
    {
        return (array) (\Illuminate\Support\Facades\Cache::get('enterprise.images.'.$server->id)['tags'] ?? []);
    }

    /**
     * Ask the node for its images through the agent (host-mode cron.exec), at most once per
     * $every seconds. Independent of the heartbeat inventory, which can lag on a busy node.
     */
    public function scanImages(Server $server, int $every = 0): ?Job
    {
        $lock = 'enterprise.images.scan.'.$server->id;
        if ($every > 0 && ! \Illuminate\Support\Facades\Cache::add($lock, 1, $every)) {
            return null;
        }
        $job = $this->jobs->create('enterprise.exec', ['execute'], $server, ['kind' => 'images', 'server_id' => $server->id], null, $server->organization_id);
        $this->bus->dispatch($server, 'cron.exec', [
            'mode' => 'host', 'command' => (string) config('enterprise.images_command').' '.escapeshellarg((string) config('enterprise.image')), 'timeout' => 60,
        ], $job);

        return $job;
    }

    private function imagesScanned(AgentCommand $command, Job $job, array $out): void
    {
        $exit = $out['exit_code'] ?? ($command->status === 'succeeded' ? 0 : null);
        if ($exit === null || (int) $exit !== 0) {
            $this->jobs->fail($job, Str::limit((string) ($command->message ?: ($out['output'] ?? 'docker images failed')), 250));

            return;
        }
        $repo = (string) config('enterprise.image');
        $tags = collect(preg_split('/\R/', (string) ($out['output'] ?? '')))->map(fn ($l) => trim($l))
            ->filter(fn ($l) => str_starts_with($l, $repo.':') && ! str_ends_with($l, ':<none>'))->unique()->values()->all();
        \Illuminate\Support\Facades\Cache::put('enterprise.images.'.$job->payload['server_id'], ['tags' => $tags, 'checked_at' => now()->toIso8601ZuluString()], now()->addDay());
        $this->jobs->succeed($job, ['tags' => count($tags)]);
    }

    /** Enterprise nodes for the organisation (tagged first, as placement does). */
    public function enterpriseNodes(string $orgId)
    {
        return $this->preferTagged($this->candidateNodes($orgId));
    }

    /**
     * GET versions: worksuite-server tags on the enterprise nodes, from the heartbeat inventory
     * merged with the direct scan. A node whose heartbeat is older than images_stale_after is
     * scanned again (the answer then arrives on a later call).
     */
    public function versions(string $orgId): array
    {
        $repo = (string) config('enterprise.image');
        $stale = (int) config('enterprise.images_stale_after', 300);
        $byTag = [];
        $nodes = [];
        foreach ($this->enterpriseNodes($orgId) as $server) {
            $inventory = \App\Models\Image::allTenants()->where('server_id', $server->id)->get(['repository', 'tags', 'size_mb', 'docker_created_at'])
                ->flatMap(fn ($i) => collect((array) ($i->tags ?? []))->map(fn ($t) => ['tag' => $t, 'size_mb' => $i->size_mb, 'created' => $i->docker_created_at?->toIso8601ZuluString()]))
                ->filter(fn ($t) => str_starts_with($t['tag'], $repo.':'));
            $scan = \Illuminate\Support\Facades\Cache::get('enterprise.images.'.$server->id);
            $inventoryStale = ! $server->last_heartbeat_at || $server->last_heartbeat_at->lt(now()->subSeconds($stale)) || $server->docker_error;
            $scanStale = ! $scan || \Illuminate\Support\Carbon::parse($scan['checked_at'])->lt(now()->subSeconds($stale));
            if ($scanStale) {
                rescue(fn () => $this->scanImages($server, 60), report: true);
            }
            $tags = $inventory->keyBy('tag');
            foreach ((array) ($scan['tags'] ?? []) as $t) {
                $tags[$t] ??= ['tag' => $t, 'size_mb' => null, 'created' => null];
            }
            foreach ($tags as $t) {
                $version = substr($t['tag'], strlen($repo) + 1);
                $byTag[$version] ??= ['version' => $version, 'published_at' => $t['created'], 'image' => $t['tag'], 'created' => $t['created'], 'size_mb' => $t['size_mb'], 'nodes' => []];
                $byTag[$version]['nodes'][] = $server->name;
                $byTag[$version]['created'] ??= $t['created'];
                $byTag[$version]['published_at'] ??= $t['created'];
            }
            $nodes[] = ['id' => $server->id, 'name' => $server->name, 'inventory_at' => $server->last_heartbeat_at?->toIso8601ZuluString(),
                'inventory_stale' => (bool) $inventoryStale, 'scanned_at' => $scan['checked_at'] ?? null];
        }
        $versions = collect($byTag)->sortByDesc(fn ($v) => [$v['version'] === 'latest' ? 0 : 1, $v['created'] ?? '', $v['version']])->values()->all();

        return ['image' => $repo, 'versions' => $versions, 'nodes' => $nodes];
    }

    /** GET nodes: eligible enterprise nodes with free CPU / RAM / disk. */
    public function nodes(string $orgId): array
    {
        return $this->enterpriseNodes($orgId)->map(function (Server $s) {
            $c = $this->scheduler->capacity($s);
            $why = $this->scheduler->ineligible($s, $c, 0, 0.0, false);

            return ['id' => $s->id, 'name' => $s->name, 'region' => $s->region, 'tags' => array_values((array) ($s->tags ?? [])),
                'status' => $s->status, 'eligible' => ! $why, 'reason' => $why ?: null,
                'cpu' => ['allocatable' => $c['allocatable_cpu'], 'free' => $c['free_cpu'], 'used_pct' => $s->cpu_pct],
                'ram_mb' => ['allocatable' => $c['allocatable_ram_mb'], 'free' => $c['free_ram_mb'], 'used_pct' => $s->ram_pct],
                'disk_gb' => ['total' => $s->disk_gb, 'free' => $c['free_disk_gb'], 'used_pct' => $s->disk_pct],
                'free' => ['cpus' => (float) $c['free_cpu'], 'ram_mb' => (int) $c['free_ram_mb'], 'disk_gb' => $c['free_disk_gb']],
                'containers' => $c['containers'], 'enterprise_deployments' => EnterpriseDeployment::where('server_id', $s->id)->whereNotIn('status', ['deleted', 'purged'])->count()];
        })->values()->all();
    }

    /** POST resize: a preset size, or custom web limits. Updates limits + FPM_MAX_CHILDREN and redeploys web + worker. */
    public function resize(EnterpriseDeployment $dep, array $in): EnterpriseDeployment
    {
        if (in_array($dep->status, ['deleted', 'purged', 'queued', 'stopped'], true) || ! $dep->app_application_id) {
            throw ApiException::conflict("The deployment is {$dep->status}; it cannot be resized now.");
        }
        $from = $this->sizeOf($dep);
        if (! empty($in['size'])) {
            $size = config('enterprise.sizes')[$in['size']];
            $changes = ['size' => $in['size'], 'limits' => null];
        } else {
            $size = $from;
            $b = (array) config('enterprise.custom_limits');
            isset($in['cpus']) && $size['app_cpus'] = (float) $in['cpus'];
            if (isset($in['ram_mb'])) {
                $size['app_ram_mb'] = (int) $in['ram_mb'];
                $size['fpm_max_children'] = max(4, intdiv((int) $in['ram_mb'], (int) ($b['mb_per_fpm_child'] ?? 80)));
            }
            isset($in['disk_gb']) && $size['disk_gb'] = (int) $in['disk_gb'];
            $size['label'] = 'Custom';
            $changes = ['limits' => $size];
        }
        $extraRam = ($size['app_ram_mb'] + $size['worker_ram_mb']) - ($from['app_ram_mb'] + $from['worker_ram_mb']);
        $extraCpu = ($size['app_cpus'] + $size['worker_cpus']) - ($from['app_cpus'] + $from['worker_cpus']);
        $server = Server::allTenants()->findOrFail($dep->server_id);
        if ($extraRam > 0 || $extraCpu > 0) {
            $c = $this->scheduler->capacity($server);
            if ($c['free_ram_mb'] < $extraRam || $c['free_cpu'] < $extraCpu) {
                throw ApiException::conflict("{$server->name} has {$c['free_ram_mb']} MB RAM and {$c['free_cpu']} CPU free; this resize needs {$extraRam} MB and {$extraCpu} CPU more.", ['reason' => 'no_capacity']);
            }
        }
        $dep->update($changes);
        $this->tenant->as($dep->organization_id, function () use ($dep, $size) {
            foreach (['app' => $dep->app_application_id, 'worker' => $dep->worker_application_id] as $role => $id) {
                if (! $id || ! ($app = Application::find($id))) {
                    continue;
                }
                $app->update(['memory_limit_mb' => $size[$role.'_ram_mb'], 'cpu_limit' => $size[$role.'_cpus']]);
                EnvVar::where('application_id', $app->id)->where('key', config('enterprise.env.fpm_max_children'))->get()->each->update(['value' => (string) ($size['fpm_max_children'] ?? 10)]);
                $this->deployments->start($app->fresh(), User::find(auth()->id()), 'redeploy');
            }
        });
        $label = ($size['label'] ?? 'Custom').": web {$size['app_cpus']} CPU / {$size['app_ram_mb']} MB, worker {$size['worker_cpus']} CPU / {$size['worker_ram_mb']} MB, {$size['disk_gb']} GB disk";
        $this->step($dep, 'resize', 'running', 'Resizing to '.$label, 'Resize');
        $this->step($dep, 'redeploy', 'running', 'Redeploying with the new limits', 'Redeploy');
        $this->reviveAfterRedeploy($dep);
        $this->log('enterprise.deployment.resize', $dep, ['size' => $dep->size, 'limits' => $dep->limits]);

        return $dep->fresh();
    }

    /** A redeploy of a failed deployment puts it back on the step machine. */
    private function reviveAfterRedeploy(EnterpriseDeployment $dep): void
    {
        $dep->refresh();
        if ($dep->status !== 'failed') {
            return;
        }
        if ($dep->activated_at) {
            $this->setStatus($dep, 'active');

            return;
        }
        Application::allTenants()->whereIn('id', array_filter([$dep->app_application_id, $dep->worker_application_id]))->where('status', 'failed')->update(['status' => 'deploying']);
        foreach (['app', 'worker'] as $step) {
            $this->step($dep, $step, 'running', 'Redeploying '.self::image($dep->version));
        }
        $this->setStatus($dep, 'provisioning');
    }

    /** GET metrics: CPU / RAM series of the deployment's containers and its volumes' disk use. */
    public function metrics(EnterpriseDeployment $dep, string $range): array
    {
        $range = isset(MetricsService::RANGES[$range]) ? $range : '1h';
        $since = MetricsService::since($range);
        $series = [];
        foreach ($this->containersOf($dep) as $role => $c) {
            $points = $c ? \App\Models\Metric::where('container_id', $c->id)->where('recorded_at', '>=', $since)->orderBy('recorded_at')->get(['cpu_pct', 'mem_mb', 'recorded_at']) : collect();
            $series[] = ['role' => $role, 'name' => $c?->name,
                'cpu' => $points->map(fn ($p) => ['t' => $p->recorded_at->toIso8601ZuluString(), 'v' => $p->cpu_pct])->values(),
                'ram' => $points->map(fn ($p) => ['t' => $p->recorded_at->toIso8601ZuluString(), 'v' => $p->mem_mb])->values()];
        }
        $names = collect(config('enterprise.volumes'))->keys()->map(fn ($s) => 'ent-'.$dep->slug.'-'.$s)
            ->merge(Database::allTenants()->whereIn('id', array_filter([$dep->database_id, $dep->redis_id]))->get()->map(fn ($d) => 'db-'.Str::slug($d->name).'-data'));
        $volumes = \App\Models\Volume::allTenants()->where('server_id', $dep->server_id)->whereIn('name', $names->all())->get(['name', 'size_mb']);
        $size = $this->sizeOf($dep);

        // Cloud Admin's combined series: one point per heartbeat, summed over the containers.
        $disk = round((float) $volumes->sum('size_mb') / 1024, 2);
        $combined = [];
        foreach ($series as $c) {
            foreach ($c['cpu'] as $i => $pt) {
                $at = $pt['t'];
                $combined[$at] ??= ['at' => $at, 'cpu_percent' => 0.0, 'memory_mb' => 0.0, 'disk_gb' => $disk];
                $combined[$at]['cpu_percent'] = round($combined[$at]['cpu_percent'] + (float) $pt['v'], 1);
                $combined[$at]['memory_mb'] = round($combined[$at]['memory_mb'] + (float) ($c['ram'][$i]['v'] ?? 0), 1);
            }
        }
        ksort($combined);

        return [
            'range' => $range,
            'series' => array_values($combined),
            'containers' => $series,
            'disk' => ['used_mb' => round((float) $volumes->sum('size_mb'), 1), 'limit_gb' => $size['disk_gb'] ?? null,
                'volumes' => $volumes->map(fn ($v) => ['name' => $v->name, 'size_mb' => $v->size_mb])->values()],
        ];
    }

    /** Every domain of the web app: the platform address first. */
    public function domainList(EnterpriseDeployment $dep): array
    {
        if (! $dep->app_application_id) {
            return [];
        }
        $primary = $dep->custom_domain ?: $dep->hostname;

        return Domain::allTenants()->where('application_id', $dep->app_application_id)->orderBy('created_at')->get()
            ->sortBy(fn ($d) => $d->name === $dep->hostname ? 0 : 1)->map(fn (Domain $d) => $this->domainShape($dep, $d, $primary))->values()->all();
    }

    public static function dnsState(?string $s): string
    {
        return $s === 'verified' ? 'ok' : (in_array($s, ['failed', 'error', 'conflict'], true) ? 'failed' : 'pending');
    }

    public static function tlsState(?string $s): string
    {
        return $s === 'active' ? 'ok' : (in_array($s, ['failed', 'error'], true) ? 'failed' : 'pending');
    }

    /** Cloud Admin's domain shape: {domain, status, primary, tls_status, kind, records[{type,name,value,ttl}], checked_at, error}. */
    private function domainShape(EnterpriseDeployment $dep, Domain $d, string $primary): array
    {
        $kind = $d->name === $dep->hostname ? 'platform' : 'custom';
        $published = (array) (collect($dep->steps ?? [])->firstWhere('name', $kind === 'platform' ? 'domain' : 'custom_domain')['dns'] ?? []);
        $bad = collect($published)->first(fn ($r) => in_array($r['status'] ?? '', ['conflict', 'failed'], true) && ($r['name'] ?? '') === $d->name);
        $records = $d->platform_subdomain ? [] : rescue(fn () => $this->domains->expectedRecords($d), [], false);

        return [
            'domain' => $d->name, 'name' => $d->name, 'kind' => $kind, 'primary' => $d->name === $primary,
            'status' => $bad ? 'failed' : self::dnsState($d->dns_status), 'tls_status' => self::tlsState($d->https_status),
            'dns_raw' => $d->dns_status, 'https_raw' => $d->https_status ?? 'pending',
            'records' => collect($records)->map(fn ($r) => ['type' => $r['type'], 'name' => $r['name'], 'value' => $r['value'], 'ttl' => $r['ttl'] ?? 300])->values()->all(),
            'checked_at' => $d->updated_at?->toIso8601ZuluString(),
            'error' => $bad['message'] ?? null,
        ];
    }

    private function appDomain(EnterpriseDeployment $dep, string $name): Domain
    {
        $d = $dep->app_application_id ? Domain::allTenants()->where('application_id', $dep->app_application_id)->where('name', $name)->first() : null;
        if (! $d) {
            throw new ApiException('domain_not_found', "No domain {$name} on this deployment.", 404);
        }

        return $d;
    }

    public function addDomain(EnterpriseDeployment $dep, string $name): array
    {
        if (! $dep->app_application_id || in_array($dep->status, ['deleted', 'purged'], true)) {
            throw ApiException::conflict("The deployment is {$dep->status}; domains can be added once the server is up.");
        }
        $published = $this->tenant->as($dep->organization_id, function () use ($dep, $name) {
            $app = Application::findOrFail($dep->app_application_id);
            try {
                $d = $this->domains->create(['name' => $name, 'application_id' => $app->id, 'target_port' => $app->port]);
            } catch (ApiException $e) {
                throw ApiException::validation(['domain' => [$e->getMessage()]]);
            }

            return (array) ($d->publishedRecords ?? []);
        });
        $this->step($dep, 'custom_domain', 'running', trim('Waiting for DNS: '.$name.' '.(self::zoneSummary($published) ?? '')), 'Your own domain');
        $this->stepDns($dep, 'custom_domain', $published);
        $this->log('enterprise.domain.add', $dep, ['domain' => $name]);

        return $this->domainView($dep, $name);
    }

    public function verifyDomain(EnterpriseDeployment $dep, string $name): array
    {
        $d = $this->appDomain($dep, $name);
        $this->tenant->as($dep->organization_id, fn () => rescue(fn () => $this->domains->verify($d), report: false));
        $d->refresh();
        if ($name !== $dep->hostname) {
            $live = $d->dns_status === 'verified' && $d->https_status === 'active';
            $this->step($dep, 'custom_domain', $live ? 'succeeded' : 'running', $live ? "https://{$name} is live" : "DNS {$d->dns_status}, TLS ".($d->https_status ?? 'pending'), 'Your own domain');
        }

        return $this->domainView($dep, $name);
    }

    public function removeDomain(EnterpriseDeployment $dep, string $name): EnterpriseDeployment
    {
        if ($name === $dep->hostname) {
            throw ApiException::conflict('The platform address cannot be removed.');
        }
        $d = $this->appDomain($dep, $name);
        if ($dep->custom_domain === $name) {
            $this->switchPrimary($dep, null);
        }
        $d->delete();
        $this->log('enterprise.domain.remove', $dep, ['domain' => $name]);

        return $dep->fresh();
    }

    /** Make a domain the address: APP_URL follows and web is redeployed. */
    public function primaryDomain(EnterpriseDeployment $dep, string $name): EnterpriseDeployment
    {
        $d = $this->appDomain($dep, $name);
        if ($name !== $dep->hostname && ($d->dns_status !== 'verified' || $d->https_status !== 'active')) {
            throw ApiException::conflict("{$name} is not live yet (DNS {$d->dns_status}, TLS ".($d->https_status ?? 'pending').'); verify it first.');
        }
        $this->switchPrimary($dep, $name === $dep->hostname ? null : $name);
        $this->log('enterprise.domain.primary', $dep, ['domain' => $name]);

        return $dep->fresh();
    }

    private function switchPrimary(EnterpriseDeployment $dep, ?string $custom): void
    {
        $dep->update(['custom_domain' => $custom]);
        $this->tenant->as($dep->organization_id, function () use ($dep, $custom) {
            Domain::where('application_id', $dep->app_application_id)->get()->each(fn ($x) => $x->update(['is_primary' => $x->name === ($custom ?: $dep->hostname)]));
            $app = Application::findOrFail($dep->app_application_id);
            EnvVar::where('application_id', $app->id)->where('key', config('enterprise.env.base_url'))->get()->each->update(['value' => 'https://'.($custom ?: $dep->hostname)]);
            $this->deployments->start($app->fresh(), User::find(auth()->id()), 'redeploy');
        });
        $this->step($dep, 'redeploy', 'running', 'Redeploying for https://'.($custom ?: $dep->hostname), 'Redeploy');
    }

    private function domainView(EnterpriseDeployment $dep, string $name): array
    {
        return collect($this->domainList($dep))->firstWhere('domain', $name) ?? ['domain' => $name];
    }

    /** POST licence-refresh: re-read the licence inside the web container. */
    public function refreshLicence(EnterpriseDeployment $dep): array
    {
        if (! in_array($dep->status, ['active', 'configuring'], true) || ! $dep->app_application_id) {
            throw ApiException::conflict("The deployment is {$dep->status}; the licence can be refreshed once it is active.");
        }
        $command = 'cd '.escapeshellarg((string) config('enterprise.workdir')).' && '.config('enterprise.licence_refresh_command');
        $job = $this->tenant->as($dep->organization_id, fn () => $this->exec($dep, 'licence', $command));
        $this->step($dep, 'licence_refresh', 'running', 'Refreshing the licence', 'Licence refresh');
        $this->log('enterprise.deployment.licence_refresh', $dep, []);

        return ['job_id' => $job->id, 'status' => $dep->fresh()->status];
    }

    public const RETRYABLE = ['image', 'database', 'redis', 'app', 'worker', 'domain', 'custom_domain', 'bootstrap', 'licence', 'licence_refresh', 'redeploy', 'resize'];

    /** POST steps/{step}/retry: re-run one failed step. */
    public function retryStep(EnterpriseDeployment $dep, string $step): EnterpriseDeployment
    {
        if (! in_array($step, self::RETRYABLE, true)) {
            throw ApiException::validation(['step' => ["{$step} cannot be retried."]]);
        }
        if ($this->stepStatus($dep, $step) !== 'failed') {
            throw ApiException::conflict("The {$step} step has not failed.");
        }
        if (in_array($dep->status, ['deleted', 'purged', 'stopped'], true)) {
            throw ApiException::conflict("The deployment is {$dep->status}.");
        }
        $this->tenant->as($dep->organization_id, function () use ($dep, $step) {
            switch ($step) {
                case 'database':
                case 'redis':
                    // Drop the failed container record; advance creates it again on the same data volume.
                    $col = $step === 'database' ? 'database_id' : 'redis_id';
                    if ($db = Database::find($dep->{$col})) {
                        Container::whereKey($db->container_id)->delete();
                        Secret::whereKey($db->secret_id)->delete();
                        $db->delete();
                    }
                    $dep->update([$col => null]);
                    $this->step($dep, $step, 'running', 'Retrying');
                    break;
                case 'app':
                case 'worker':
                case 'redeploy':
                case 'resize':
                    foreach (array_filter([$dep->app_application_id, $dep->worker_application_id]) as $id) {
                        if (in_array($step, ['redeploy', 'resize'], true) || $id === ($step === 'app' ? $dep->app_application_id : $dep->worker_application_id)) {
                            Application::whereKey($id)->where('status', 'failed')->update(['status' => 'deploying']);
                            $this->deployments->start(Application::findOrFail($id), User::find(auth()->id()), 'redeploy');
                        }
                    }
                    $this->step($dep, $step, 'running', 'Retrying: redeploying '.self::image($dep->version));
                    break;
                case 'bootstrap':
                    throw ApiException::conflict('A failed bootstrap is retried by posting configure again with the wizard settings.');
                case 'licence':
                case 'licence_refresh':
                    $this->exec($dep, 'licence', 'cd '.escapeshellarg((string) config('enterprise.workdir')).' && '.config('enterprise.licence_refresh_command'));
                    $this->step($dep, 'licence_refresh', 'running', 'Refreshing the licence', 'Licence refresh');
                    $step === 'licence' && $this->step($dep, 'licence', 'running', 'Retrying the licence', 'Licence');
                    break;
                default: // image, domain, custom_domain: the machine re-checks them
                    $this->step($dep, $step, 'running', 'Retrying');
            }
        });
        $dep->refresh();
        if ($dep->status === 'failed') {
            $this->setStatus($dep, $dep->activated_at ? 'active' : 'provisioning');
        }
        $this->log('enterprise.deployment.retry', $dep, ['step' => $step]);
        rescue(fn () => $this->advance($dep->fresh()), report: true);

        return $dep->fresh();
    }

    // --- steps, status, webhooks -------------------------------------------------------------

    public function stepStatus(EnterpriseDeployment $dep, string $name): ?string
    {
        return collect($dep->steps ?? [])->firstWhere('name', $name)['status'] ?? null;
    }

    public function step(EnterpriseDeployment $dep, string $name, string $status, ?string $message = null, ?string $label = null): void
    {
        $steps = $dep->steps ?? [];
        $now = now()->toIso8601ZuluString();
        $i = collect($steps)->search(fn ($s) => $s['name'] === $name);
        if ($i === false) {
            $steps[] = ['name' => $name, 'label' => $label ?? self::STEPS[$name] ?? ucfirst($name), 'status' => 'pending', 'started_at' => null, 'finished_at' => null, 'message' => null];
            $i = count($steps) - 1;
        } elseif (in_array($steps[$i]['status'], ['succeeded', 'failed'], true) && $status === 'running' && in_array($name, ['redeploy', 'bootstrap', 'custom_domain'], true)) {
            $steps[$i]['finished_at'] = null;
            $steps[$i]['started_at'] = null;
        }
        if ($steps[$i]['status'] === $status && $steps[$i]['message'] === $message) {
            return;
        }
        $steps[$i]['status'] = $status;
        $steps[$i]['message'] = $message;
        $steps[$i]['started_at'] ??= $now;
        $steps[$i]['finished_at'] = in_array($status, ['succeeded', 'failed'], true) ? $now : null;
        $dep->update(['steps' => $steps]);
    }

    private function fail(EnterpriseDeployment $dep, string $step, string $message): void
    {
        $this->step($dep, $step, 'failed', $message);
        $dep->update(['status_reason' => $message]);
        $this->setStatus($dep, 'failed');
    }

    public function setStatus(EnterpriseDeployment $dep, string $status): void
    {
        if ($dep->status === $status) {
            return;
        }
        $from = $dep->status;
        $dep->update(['status' => $status] + (in_array($status, ['active', 'awaiting_setup', 'provisioning'], true) ? ['status_reason' => null] : []));
        $this->enqueue($dep->fresh(), 'deployment.status', ['previous_status' => $from]);
    }

    public function enqueue(EnterpriseDeployment $dep, string $event, array $extra = []): ?EnterpriseWebhook
    {
        if (! $dep->callback_url) {
            return null;
        }

        return EnterpriseWebhook::create([
            'enterprise_deployment_id' => $dep->id, 'event' => $event, 'next_attempt_at' => now(),
            'payload' => ['event' => $event, 'occurred_at' => now()->toIso8601ZuluString(), 'deployment' => $this->present($dep)] + $extra,
        ]);
    }

    private function log(string $action, EnterpriseDeployment $dep, array $meta): void
    {
        $this->audit->log($action, 'EnterpriseDeployment', $dep->id, $meta + ['external_id' => $dep->external_id], $dep->organization_id);
    }
}
