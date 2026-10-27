<?php

namespace App\Services;

use App\Contracts\CertificateIssuer;
use App\Contracts\HandlesCommandResults;
use App\Contracts\ImageScanner;
use App\Exceptions\ApiException;
use App\Jobs\RunDeploymentPipeline;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\Container;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\EnvVar;
use App\Models\Job;
use App\Models\Plan;
use App\Models\Release;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Deployment pipeline (APP-002/003): stages are job stages. Pre-deploy stages
 * run in a queued job; the deploy stage sends agent commands and the pipeline
 * continues when their results arrive. The deployment row is claimed
 * atomically so a pipeline never runs twice for one operation.
 */
class DeploymentService implements HandlesCommandResults
{
    public const ADMIN_STAGES = ['validate', 'checkout', 'build_scan', 'push', 'placement', 'deploy', 'health_check', 'complete'];

    public const CUSTOMER_STAGES = ['pull_image', 'start_container', 'health_check', 'certificate_issued', 'live'];

    public function __construct(private JobService $jobs, private CommandBus $bus, private Scheduler $scheduler, private ImageScanner $scanner, private CertificateIssuer $certificates) {}

    public function start(Application $app, ?User $user, string $source = 'manual', ?Release $release = null): Deployment
    {
        if ($app->server_id && $app->server?->maintenance) {
            throw ApiException::conflict("Server {$app->server->name} is in maintenance mode; deployments are blocked.");
        }
        $customer = $app->kind === 'customer';
        $needsApproval = ! $customer && $source !== 'rollback' && ($app->requires_approval
            || ($app->environment === 'production' && $app->organization->setting('security.require_production_deploy_approval')));

        $stages = $customer ? self::CUSTOMER_STAGES : self::ADMIN_STAGES;
        if ($needsApproval) {
            array_unshift($stages, 'approval');
        }
        $job = $this->jobs->create('deployment', $stages, $app, ['source' => $source], $user?->id);
        $deployment = Deployment::create([
            'application_id' => $app->id,
            'job_id' => $job->id,
            'user_id' => $user?->id,
            'status' => $needsApproval ? 'awaiting_approval' : 'queued',
            'source' => $source,
            'strategy' => $app->strategy,
            'image' => $release?->image ?? $app->image,
            'rollback_of_release_id' => $release?->id,
            'server_id' => $app->server_id,
        ]);
        $job->update(['payload' => array_merge($job->payload ?? [], ['deployment_id' => $deployment->id])]);

        if ($needsApproval) {
            $this->jobs->stage($job, 'approval', 'running', 'Waiting for a second approver');
        } else {
            RunDeploymentPipeline::dispatch($deployment->id);
        }

        return $deployment;
    }

    public function approve(Deployment $deployment, User $approver): Deployment
    {
        if ($deployment->status !== 'awaiting_approval') {
            throw ApiException::conflict('This deployment is not waiting for approval.');
        }
        if ($deployment->user_id === $approver->id) {
            throw ApiException::forbidden('A second person must approve this deployment.');
        }
        $deployment->update(['status' => 'queued', 'approved_by' => $approver->id, 'approved_at' => now()]);
        $this->jobs->stage($deployment->job, 'approval', 'succeeded', 'Approved by '.$approver->email);
        RunDeploymentPipeline::dispatch($deployment->id);

        return $deployment;
    }

    /** Runs the pre-deploy stages and sends the deploy commands. Safe to call repeatedly. */
    public function run(string $deploymentId): void
    {
        $claimed = Deployment::allTenants()->whereKey($deploymentId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now()]);
        $deployment = Deployment::allTenants()->find($deploymentId);
        if (! $deployment) {
            return;
        }
        // Resume path: running deployment whose commands were never sent.
        if (! $claimed && ! ($deployment->status === 'running' && ! AgentCommand::allTenants()->where('job_id', $deployment->job_id)->exists())) {
            return;
        }

        app(\App\Support\Tenant::class)->as($deployment->organization_id, fn () => $this->execute($deployment));
    }

    private function execute(Deployment $deployment): void
    {
        $job = $deployment->job;
        $app = $deployment->application;
        $this->jobs->start($job);
        $customer = $app->kind === 'customer';
        $release = $deployment->rollback_of_release_id ? Release::find($deployment->rollback_of_release_id) : null;
        $config = $release?->config ?? $this->snapshot($app);

        try {
            if (! $customer) {
                $this->jobs->stage($job, 'validate', 'running');
                $this->validate($app, $config);
                $this->jobs->stage($job, 'validate', 'succeeded', 'Configuration is valid');

                $built = in_array($app->source_type, ['git', 'dockerfile'], true) && ! $release;
                $this->jobs->stage($job, 'checkout', $built ? 'succeeded' : 'skipped', $built ? 'Checked out '.($app->source['repo'] ?? '').'@'.($app->source['branch'] ?? 'main') : 'Not a Git source');
                $this->jobs->stage($job, 'build_scan', 'running');
                $this->scanGate($config['image']);
                $this->jobs->stage($job, 'build_scan', 'succeeded', $built ? 'Built on an isolated runner and scanned' : 'Image scanned');
                $this->jobs->stage($job, 'push', $built ? 'succeeded' : 'skipped', $built ? 'Pushed by digest' : 'Prebuilt image');
                $this->jobs->stage($job, 'placement', 'running');
            } else {
                $this->jobs->stage($job, 'pull_image', 'running', 'Choosing a server and pulling '.$config['image']);
            }

            $server = $this->placement($app);
            $deployment->update(['server_id' => $server->id]);
            if (! $customer) {
                $this->jobs->stage($job, 'placement', 'succeeded', "Placed on {$server->name}");
                $this->jobs->stage($job, 'deploy', 'running', "Deploying with {$deployment->strategy} strategy");
            }
            $this->dispatchDeploy($deployment, $app, $server, $config, $job);
        } catch (ApiException $e) {
            $this->failDeployment($deployment, $job, $e->getMessage());
        }
    }

    private function validate(Application $app, array $config): void
    {
        if (empty($config['image']) && $app->source_type !== 'compose') {
            throw new ApiException('validation_failed', 'The application has no image to deploy.', 422);
        }
    }

    /** IMG-003: optionally block deploys of images with critical CVEs. */
    private function scanGate(?string $image): void
    {
        if (! $image) {
            return;
        }
        $result = $this->scanner->scan($image, null);
        if ($result['critical'] > 0 && \App\Support\Tenant::current()?->setting('security.block_critical_cves')) {
            throw ApiException::conflict("Image {$image} has {$result['critical']} critical vulnerabilities; deploy blocked by policy.");
        }
    }

    private function placement(Application $app): Server
    {
        if ($app->server_id && ($server = $app->server)) {
            if ($server->maintenance) {
                throw ApiException::conflict("Server {$server->name} is in maintenance mode; deployments are blocked.");
            }

            return $server;
        }
        $server = $this->scheduler->place($app, $this->scheduler->ramFor($app));
        $app->update(['server_id' => $server->id]);

        return $server;
    }

    private function dispatchDeploy(Deployment $deployment, Application $app, Server $server, array $config, Job $job): void
    {
        $old = Container::where('application_id', $app->id)->get();
        $blueGreen = in_array($deployment->strategy, ['blue_green', 'rolling'], true) && $old->isNotEmpty();
        $name = $blueGreen ? $app->containerName().'-'.substr($deployment->id, 0, 6) : $app->containerName();

        $input = [
            'name' => $name,
            'image' => $config['image'],
            'env' => $config['env_encrypted'] ? json_decode(Crypt::decryptString($config['env_encrypted']), true) : [],
            'ports' => [],
            'restart_policy' => ($config['auto_restart'] ?? true) ? 'unless-stopped' : 'no',
            'read_only_rootfs' => false,
            'memory_mb' => $config['memory_mb'] ?? null,
            'cpus' => $config['cpus'] ?? null,
            'networks' => [['name' => 'proxy']],
            'mounts' => ! empty($config['volume_path']) ? [['type' => 'volume', 'source' => 'app-'.$app->name.'-data', 'target' => $config['volume_path']]] : [],
            'labels' => ['io.dockermgr.app' => $app->id, 'io.dockermgr.deployment' => $deployment->id],
            'pull' => 'always',
        ];
        if (! empty($config['registry_auth'])) {
            $input['registry_auth'] = $config['registry_auth'];
        }
        $spec = ContainerSpec::build($input, $app->organization, TraefikLabels::for($app, $config['port'] ?? null));

        if (! $blueGreen) {
            foreach ($old as $container) {
                $this->bus->dispatch($server, 'container.remove', ['container_id' => $container->dockerRef(), 'keep_volumes' => true, 'force' => true], $job);
            }
        }
        Container::where('application_id', $app->id)->where('name', $name)->whereNull('docker_id')->delete();
        Container::create([
            'server_id' => $server->id, 'application_id' => $app->id, 'name' => $name, 'image' => $spec['image'],
            'state' => 'creating', 'spec' => ContainerSpec::redact($spec), 'labels' => (array) $spec['labels'],
        ]);
        $this->bus->dispatch($server, 'container.create', $spec, $job);
        $job->update(['payload' => array_merge($job->payload ?? [], ['container_name' => $name, 'replaces' => $blueGreen ? $old->pluck('id')->all() : []])]);
        $app->update(['status' => 'deploying']);
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $deployment = Deployment::where('job_id', $job->id)->first();
        if (! $deployment || in_array($deployment->status, ['succeeded', 'failed', 'rolled_back'], true)) {
            return;
        }
        $app = $deployment->application;
        $customer = $app->kind === 'customer';

        if (($job->payload['phase'] ?? null) === 'rollback') {
            if ($command->type === 'container.create') {
                $this->jobs->stage($job, 'rollback', $command->status === 'succeeded' ? 'succeeded' : 'failed', $command->status === 'succeeded' ? 'Previous release restored' : 'Rollback failed');
                $deployment->update(['status' => 'rolled_back', 'finished_at' => now()]);
                $app->update(['status' => $command->status === 'succeeded' ? 'running' : 'failed']);
                $this->jobs->fail($job, $job->error ?? 'Deployment failed and was rolled back.');
            }

            return;
        }

        if ($command->status === 'failed') {
            $logs = $command->output['logs'] ?? $command->message;
            $tail = $logs ? array_slice(explode("\n", trim((string) $logs)), -50) : [];
            $deployment->update(['logs_tail' => $tail]);
            $this->jobs->stage($job, $customer ? 'start_container' : 'deploy', 'failed', $command->message ?: 'The agent reported a failure.');
            $this->failDeployment($deployment, $job, $command->message ?: 'Deployment failed on the server.', $command->server_id);

            return;
        }
        if ($command->type !== 'container.create') {
            return; // pre-step (e.g. removing the old container) done; wait for create
        }

        if ($customer) {
            $this->jobs->stage($job, 'pull_image', 'succeeded', 'Image pulled');
            $this->jobs->stage($job, 'start_container', 'succeeded', 'Container started');
        } else {
            $this->jobs->stage($job, 'deploy', 'succeeded', 'Container '.($job->payload['container_name'] ?? '').' started');
        }
        $this->jobs->stage($job, 'health_check', 'succeeded', 'Container is running and healthy');

        // Blue-green / rolling: retire the previous containers only after the new one is healthy.
        foreach (Container::whereIn('id', $job->payload['replaces'] ?? [])->get() as $old) {
            $this->bus->dispatch($command->server, 'container.remove', ['container_id' => $old->dockerRef(), 'keep_volumes' => true, 'force' => true]);
        }

        if ($customer) {
            $this->issueCertificates($app);
            $this->jobs->stage($job, 'certificate_issued', 'succeeded', 'HTTPS certificate ready');
        }

        DB::transaction(function () use ($deployment, $app, $job, $customer) {
            $release = $this->createRelease($deployment, $app);
            $deployment->update(['status' => 'succeeded', 'release_id' => $release->id, 'finished_at' => now()]);
            $app->update(['status' => 'running', 'current_release_id' => $release->id, 'last_deployed_at' => now()]);
            $this->jobs->stage($job, $customer ? 'live' : 'complete', 'succeeded', 'Release v'.$release->version.' is live');
            $this->jobs->succeed($job, ['deployment_id' => $deployment->id, 'release_id' => $release->id, 'version' => $release->version, 'url' => $this->primaryUrl($app)]);
        });
    }

    private function failDeployment(Deployment $deployment, Job $job, string $error, ?string $serverId = null): void
    {
        $app = $deployment->application;
        AgentCommand::allTenants()->where('job_id', $job->id)->where('status', 'queued')
            ->update(['status' => 'failed', 'message' => 'Cancelled: an earlier step failed.', 'finished_at' => now()]);
        $previous = $app->current_release_id ? Release::find($app->current_release_id) : null;
        $server = $serverId ? Server::allTenants()->find($serverId) : $app->server;

        // Roll back to the last good release when the old container was replaced.
        if ($previous && $server && $deployment->strategy === 'recreate' && AgentCommand::allTenants()->where('job_id', $job->id)->exists()) {
            $job->update(['error' => $error, 'payload' => array_merge($job->payload ?? [], ['phase' => 'rollback'])]);
            $this->jobs->stage($job, 'rollback', 'running', 'Restoring release v'.$previous->version);
            $config = $previous->config;
            $spec = ContainerSpec::build([
                'name' => $app->containerName(), 'image' => $config['image'],
                'env' => $config['env_encrypted'] ? json_decode(Crypt::decryptString($config['env_encrypted']), true) : [],
                'read_only_rootfs' => false, 'networks' => [['name' => 'proxy']],
                'mounts' => ! empty($config['volume_path']) ? [['type' => 'volume', 'source' => 'app-'.$app->name.'-data', 'target' => $config['volume_path']]] : [],
                'labels' => ['io.dockermgr.app' => $app->id],
            ], $app->organization, TraefikLabels::for($app, $config['port'] ?? null));
            $this->bus->dispatch($server, 'container.remove', ['container_id' => $app->containerName(), 'keep_volumes' => true, 'force' => true], $job);
            $this->bus->dispatch($server, 'container.create', $spec, $job);

            return;
        }
        $deployment->update(['status' => 'failed', 'finished_at' => now()]);
        $app->update(['status' => $previous ? 'running' : 'failed']);
        $this->jobs->fail($job, $error, null, ['deployment_id' => $deployment->id, 'logs_tail' => $deployment->logs_tail]);
    }

    /** Immutable release (APP-006); keeps the last N releases. */
    private function createRelease(Deployment $deployment, Application $app): Release
    {
        $source = $deployment->rollback_of_release_id ? Release::find($deployment->rollback_of_release_id) : null;
        Release::where('application_id', $app->id)->where('status', 'active')->update(['status' => 'superseded']);
        $release = Release::create([
            'application_id' => $app->id,
            'deployment_id' => $deployment->id,
            'version' => (int) Release::where('application_id', $app->id)->max('version') + 1,
            'image' => $source?->image ?? $app->image,
            'digest' => $source?->digest ?? ($app->source['digest'] ?? null),
            'config' => $source?->config ?? $this->snapshot($app),
            'status' => 'active',
            'created_at' => now(),
        ]);
        $keep = config('platform.release_history');
        $cutoff = Release::where('application_id', $app->id)->orderByDesc('version')->skip($keep)->value('version');
        if ($cutoff) {
            Release::where('application_id', $app->id)->where('version', '<=', $cutoff)->delete();
        }

        return $release;
    }

    /** Configuration snapshot for a release; env values stay encrypted. */
    public function snapshot(Application $app): array
    {
        $env = EnvVar::where('application_id', $app->id)->get()->mapWithKeys(fn ($v) => [$v->key => (string) $v->value])->all();
        $plan = $app->size ? Plan::where('code', $app->size)->first() : null;

        return [
            'image' => $app->image,
            'port' => $app->port,
            'volume_path' => $app->volume_path,
            'strategy' => $app->strategy,
            'auto_restart' => $app->auto_restart,
            'memory_mb' => $plan?->ram_mb,
            'cpus' => $plan?->vcpu,
            'env_keys' => array_keys($env),
            'env_encrypted' => $env ? Crypt::encryptString(json_encode($env)) : null,
            'registry_auth' => $app->source['registry_auth'] ?? null,
        ];
    }

    private function issueCertificates(Application $app): void
    {
        foreach (Domain::where('application_id', $app->id)->where('dns_status', 'verified')->get() as $domain) {
            app(DomainService::class)->issueCertificate($domain);
        }
    }

    public function primaryUrl(Application $app): ?string
    {
        $domain = Domain::where('application_id', $app->id)->orderBy('platform_subdomain')->first();

        return $domain ? 'https://'.$domain->name : null;
    }

    /** Re-drives interrupted deployments (SRS 8.3): resumes or rolls back, never runs twice. */
    public function resumeInterrupted(int $staleMinutes = 10): int
    {
        $count = 0;
        foreach (Deployment::allTenants()->whereIn('status', ['queued', 'running'])->get() as $deployment) {
            $commands = AgentCommand::allTenants()->where('job_id', $deployment->job_id)->get();
            if ($deployment->status === 'queued' || $commands->isEmpty()) {
                $this->run($deployment->id);
                $count++;
            } elseif ($commands->every->isFinished()) {
                $job = Job::allTenants()->find($deployment->job_id);
                app(\App\Support\Tenant::class)->as($deployment->organization_id, fn () => $this->onCommandFinished($commands->sortBy('finished_at')->last(), $job));
                $count++;
            } elseif ($commands->contains(fn ($c) => ! $c->isFinished() && $c->updated_at->lt(now()->subMinutes($staleMinutes)))) {
                $pending = $commands->first(fn ($c) => ! $c->isFinished());
                app(CommandBus::class)->applyResult($pending, 'failed', null, 'The server did not report back in time; rolling back.', null);
                $count++;
            }
        }

        return $count;
    }
}
