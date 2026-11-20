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

        return ! Domain::allTenants()->where('name', self::hostFor($slug))->exists();
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
        $candidates = Server::allTenants()
            ->where(fn ($q) => $q->where('organization_id', $orgId)->orWhere('shared_pool', true))
            ->get()->filter(fn (Server $s) => $s->hasRole('app'));
        if ($nodeId) {
            $candidates = $candidates->where('id', $nodeId);
            if ($candidates->isEmpty()) {
                throw ApiException::validation(['node_id' => ['Unknown node, or it does not take workloads.']]);
            }
        }
        if ($region) {
            $candidates = $candidates->where('region', $region);
        }
        $tag = (string) config('enterprise.node_tag');
        $tagged = $candidates->filter(fn (Server $s) => in_array($tag, (array) ($s->tags ?? []), true));
        if (! $nodeId && $tagged->isNotEmpty()) {
            $candidates = $tagged;
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

    /** One step of the machine: start what is due, record what finished. Safe to call repeatedly. */
    public function advance(EnterpriseDeployment $dep): void
    {
        if (! in_array($dep->status, ['provisioning', 'configuring', 'active'], true)) {
            return;
        }
        $this->tenant->as($dep->organization_id, function () use ($dep) {
            $size = config('enterprise.sizes')[$dep->size];
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
        $node = Server::allTenants()->find($dep->server_id)?->name ?? 'the node';
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
                ]),
            ]);
            foreach ($vars as [$k, $v, $secret]) {
                EnvVar::create(['application_id' => $app->id, 'key' => $k, 'value' => $v, 'secret' => $secret]);
            }
            if ($public) {
                $platform = config('enterprise.domain') === config('platform.domain');
                $this->domains->create(['name' => $dep->hostname, 'application_id' => $app->id, 'target_port' => $app->port, 'platform_subdomain' => $platform, 'is_primary' => true], $platform);
                $dep->update(['app_application_id' => $app->id]);
            } else {
                $dep->update(['worker_application_id' => $app->id]);
            }
            $this->deployments->start($app, $service, 'manual');
            $this->step($dep, $role, 'running', 'Deploying '.self::image($dep->version));
        }
        $this->step($dep, 'domain', 'running', $dep->hostname);
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
        $this->step($dep, $step, $done ? 'succeeded' : 'running', $done ? "https://{$domain->name} is live" : "DNS {$domain->dns_status}, TLS ".($domain->https_status ?? 'pending'));
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
                    $this->domains->create(['name' => $customDomain, 'application_id' => $app->id, 'target_port' => $app->port]);
                } catch (ApiException $e) {
                    throw ApiException::validation(['custom_domain' => [$e->getMessage()]]);
                }
                $dep->update(['custom_domain' => $customDomain]);
                // The image takes its address from APP_URL: point it at the customer's domain and redeploy web.
                EnvVar::where('application_id', $app->id)->where('key', config('enterprise.env.base_url'))->get()->each->update(['value' => 'https://'.$customDomain]);
                $this->pendingRedeploy = $app;
                $this->step($dep, 'custom_domain', 'running', 'Waiting for DNS: '.$customDomain, 'Your own domain');
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
        $dep->update([$kind === 'bootstrap' ? 'bootstrap_job_id' : 'status_job_id' => $job->id] + ($kind === 'status' ? ['status_checked_at' => now()] : []));

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

        $dep = EnterpriseDeployment::find($job->payload['deployment_id'] ?? null);
        $out = (array) ($command->output ?? []);
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
            } else {
                $roles = $action === 'restart' ? ['app', 'worker'] : ['database', 'redis', 'app', 'worker'];
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
        $size = config('enterprise.sizes')[$dep->size] ?? [];
        $domainView = function (?string $name) {
            $d = $name ? Domain::allTenants()->where('name', $name)->first() : null;

            return $d ? ['name' => $d->name, 'dns_status' => $d->dns_status, 'https_status' => $d->https_status ?? 'pending',
                'records' => $d->platform_subdomain ? [] : rescue(fn () => $this->domains->expectedRecords($d), [], false)] : null;
        };
        $custom = $domainView($dep->custom_domain);
        $customLive = $custom && $custom['dns_status'] === 'verified' && $custom['https_status'] === 'active';

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
            ],
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
            'app_status' => $dep->app_status,
            'bootstrap' => $dep->bootstrap_result,
            'created_at' => $dep->created_at?->toIso8601ZuluString(),
            'updated_at' => $dep->updated_at?->toIso8601ZuluString(),
            'activated_at' => $dep->activated_at?->toIso8601ZuluString(),
            'deleted_at' => $dep->deleted_at?->toIso8601ZuluString(),
            'purged_at' => $dep->purged_at?->toIso8601ZuluString(),
        ];
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
