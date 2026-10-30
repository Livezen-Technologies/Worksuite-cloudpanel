<?php

namespace App\Services;

use App\Contracts\CertificateIssuer;
use App\Contracts\HandlesCommandResults;
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
use App\Support\ScanReport;
use App\Support\Tenant;
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

    public function __construct(private JobService $jobs, private CommandBus $bus, private Scheduler $scheduler, private CertificateIssuer $certificates, private BuildService $builds, private ScanService $scans) {}

    public function start(Application $app, ?User $user, string $source = 'manual', ?Release $release = null): Deployment
    {
        if ($app->server_id && $app->server?->maintenance) {
            throw ApiException::conflict("Server {$app->server->name} is in maintenance mode; deployments are blocked.");
        }
        $customer = $app->kind === 'customer';
        if (! $release && $this->builds->needsBuild($app)) {
            $this->builds->assertWithinMinutes($app);
        }
        $needsApproval = ! $customer && $source !== 'rollback' && ($app->requires_approval
            || ($app->environment_id && $app->environmentModel?->requires_approval)
            || ($app->environment === 'production' && $app->organization->setting('security.require_production_deploy_approval')));

        $stages = $customer ? self::CUSTOMER_STAGES : self::ADMIN_STAGES;
        if ($customer && ! $release && $this->builds->needsBuild($app)) {
            array_unshift($stages, 'build');
        }
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
        $build = ! $release && $this->builds->needsBuild($app);

        try {
            if (! $customer) {
                $this->jobs->stage($job, 'validate', 'running');
                if (! $build) {
                    $this->validate($app, $config);
                }
                $this->jobs->stage($job, 'validate', 'succeeded', 'Configuration is valid');
            }
            if ($build) {
                $this->startBuild($deployment, $app, $job, $customer);

                return;
            }
            if (! $customer) {
                $this->jobs->stage($job, 'checkout', 'skipped', $release ? 'Rollback to a built release' : 'Not a Git source');
            }
            $this->imageReady($deployment, $app, $job, $config['image'], null);
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

    private function phase(Job $job, string $phase, array $extra = []): void
    {
        $job->update(['payload' => array_merge($job->payload ?? [], ['phase' => $phase], $extra)]);
    }

    /** checkout + build on a builder node (SRS 2.4); the pipeline continues in onBuildFinished. */
    private function startBuild(Deployment $deployment, Application $app, Job $job, bool $customer): void
    {
        $registry = $this->builds->pushRegistry($app);
        // An image that is not pushed only exists where it was built, so the builder is the deploy target.
        $target = $registry ? $app->server : $this->placement($app);
        $builder = $this->builds->pickBuilder($app, $target, $registry === null);
        $payload = $this->builds->payload($app, $deployment, $registry);
        $deployment->update(['builder_server_id' => $builder->id, 'image' => $payload['image_tag']]);
        $this->phase($job, 'build', ['push_registry_id' => $registry?->id, 'builder_server_id' => $builder->id]);
        $this->jobs->stage($job, $customer ? 'build' : 'checkout', 'running', "Cloning {$payload['repo']}@{$payload['ref']} on builder {$builder->name}");
        $this->bus->dispatch($builder, 'image.build', $payload, $job);
        $app->update(['status' => 'deploying']);
    }

    private function onBuildFinished(AgentCommand $command, Job $job, Deployment $deployment, Application $app): void
    {
        $customer = $app->kind === 'customer';
        $out = (array) ($command->output ?? []);
        $log = array_values(array_filter((array) ($out['log'] ?? []), 'is_string'));
        $deployment->update(array_filter([
            'build_seconds' => isset($out['duration_seconds']) ? (int) $out['duration_seconds'] : null,
            'build_cache_steps' => isset($out['cache_steps']) ? (int) $out['cache_steps'] : null,
            'build_cache_hits' => isset($out['cache_hits']) ? (int) $out['cache_hits'] : null,
            'build_stack' => isset($out['stack']) ? substr((string) $out['stack'], 0, 20) : null,
        ], fn ($v) => $v !== null));
        if ($command->status !== 'succeeded') {
            $deployment->update(['logs_tail' => array_slice($log, -50)]);
            $cloneFailed = str_contains((string) $command->message, 'git clone') || str_contains((string) $command->message, 'repositor');
            if (! $customer) {
                $this->jobs->stage($job, 'checkout', $cloneFailed ? 'failed' : 'succeeded', $cloneFailed ? $command->message : 'Checked out');
                if (! $cloneFailed) {
                    $this->jobs->stage($job, 'build_scan', 'failed', $command->message ?: 'Build failed');
                }
            } else {
                $this->jobs->stage($job, 'build', 'failed', $command->message ?: 'Build failed');
            }
            $this->failDeployment($deployment, $job, 'Build failed: '.($command->message ?: 'unknown error'));

            return;
        }
        $pushed = ! empty($out['repo_digest']);
        $image = $pushed ? $out['repo_digest'] : $command->payload['image_tag'];
        $commit = isset($out['commit']) ? substr((string) $out['commit'], 0, 12) : null;
        $deployment->update(['image' => $image, 'image_digest' => $out['digest'] ?? null]);
        $app->update(['image' => $image]);
        $this->phase($job, 'built', ['pushed' => $pushed, 'commit' => $out['commit'] ?? null]);
        if ($customer) {
            $this->jobs->stage($job, 'build', 'succeeded', 'Built '.$command->payload['image_tag'].($commit ? " from {$commit}" : '').$this->cacheNote($deployment));
        } else {
            $this->jobs->stage($job, 'checkout', 'succeeded', 'Checked out '.$command->payload['repo'].'@'.$command->payload['ref'].($commit ? " ({$commit})" : ''));
            $this->jobs->stage($job, 'build_scan', 'running', 'Built '.$command->payload['image_tag'].' ('.substr((string) ($out['image_id'] ?? ''), 0, 19).')'.$this->cacheNote($deployment).'; scanning');
        }
        $this->imageReady($deployment, $app, $job, $image, $command->server);
    }

    private function cacheNote(Deployment $deployment): string
    {
        $parts = [];
        if ($deployment->build_stack) {
            $parts[] = "detected {$deployment->build_stack}";
        }
        if ($deployment->build_cache_steps) {
            $parts[] = "{$deployment->build_cache_hits}/{$deployment->build_cache_steps} steps cached";
        }
        if ($deployment->build_seconds !== null) {
            $parts[] = "{$deployment->build_seconds}s";
        }

        return $parts ? ' ('.implode(', ', $parts).')' : '';
    }

    /** Image exists (built or prebuilt): scan it (IMG-003), then place and deploy. */
    private function imageReady(Deployment $deployment, Application $app, Job $job, string $image, ?Server $builtOn): void
    {
        if ($app->kind === 'customer') {
            $this->proceedToDeploy($deployment, $app, $job, $image, null);

            return;
        }
        $this->jobs->stage($job, 'build_scan', 'running', $builtOn ? null : 'Scanning '.$image);
        if (ScanService::agentDriven()) {
            $scanOn = $builtOn ?? $this->placement($app);
            $this->phase($job, 'scan', ['scan_server_id' => $scanOn->id]);
            $this->bus->dispatch($scanOn, 'image.scan', ['image' => $image], $job);

            return;
        }
        $result = $this->scans->scanNow($image, $deployment->image_digest);
        $this->proceedToDeploy($deployment, $app, $job, $image, $result);
    }

    private function onScanFinished(AgentCommand $command, Job $job, Deployment $deployment, Application $app): void
    {
        $image = $command->payload['image'];
        if ($command->status !== 'succeeded') {
            if (Tenant::current()?->setting('security.block_critical_cves')) {
                $this->jobs->stage($job, 'build_scan', 'failed', 'Scan failed: '.$command->message);
                $this->failDeployment($deployment, $job, "Image {$image} could not be scanned ({$command->message}); deploy blocked by policy.");

                return;
            }
            $this->proceedToDeploy($deployment, $app, $job, $image, null, 'scan failed ('.$command->message.'); not blocking');

            return;
        }
        $result = ScanReport::fromAgent($command->output);
        $this->scans->recordOnImage($command->server, $image, $result);
        $this->proceedToDeploy($deployment, $app, $job, $image, $result);
    }

    private function proceedToDeploy(Deployment $deployment, Application $app, Job $job, string $image, ?array $scan, ?string $scanNote = null): void
    {
        $customer = $app->kind === 'customer';
        $built = isset($job->payload['builder_server_id']);
        $pushed = (bool) ($job->payload['pushed'] ?? false);
        if (! $customer) {
            if ($scan) {
                $deployment->update(['scan_result' => collect($scan)->except('vulnerabilities')->all()]);
                if (ScanReport::blocks($scan)) {
                    $this->jobs->stage($job, 'build_scan', 'failed', ScanReport::summary($scan).' — blocked by policy');
                    throw ApiException::conflict("Image {$image} has {$scan['critical']} critical vulnerabilities; deploy blocked by policy.");
                }
            }
            $this->jobs->stage($job, 'build_scan', 'succeeded', ($built ? 'Built on an isolated builder'.$this->cacheNote($deployment).'; ' : '').($scan ? 'scan: '.ScanReport::summary($scan) : ($scanNote ?? 'not scanned')));
            $this->jobs->stage($job, 'push', $built && $pushed ? 'succeeded' : 'skipped', match (true) {
                $built && $pushed => 'Pushed '.$image,
                $built => 'Not pushed: local image on the builder, deployed there',
                default => 'Prebuilt image',
            });
            $this->jobs->stage($job, 'placement', 'running');
        } else {
            $this->jobs->stage($job, 'pull_image', 'running', 'Choosing a server and pulling '.$image);
        }

        $server = $this->placement($app);
        if ($built && ! $pushed && ($job->payload['builder_server_id'] ?? null) !== $server->id) {
            throw ApiException::conflict('The image was built locally on another server and was not pushed; it cannot run on '.$server->name.'.');
        }
        $deployment->update(['server_id' => $server->id, 'image' => $image]);
        if (! $customer) {
            $this->jobs->stage($job, 'placement', 'succeeded', "Placed on {$server->name}");
            $this->jobs->stage($job, 'deploy', 'running', "Deploying with {$deployment->strategy} strategy");
        }
        $release = $deployment->rollback_of_release_id ? Release::find($deployment->rollback_of_release_id) : null;
        $config = $release?->config ?? $this->snapshot($app);
        $config['image'] = $image;
        if ($built) {
            $config['pull'] = $pushed ? 'missing' : 'never';
            $registry = $pushed ? $this->builds->pushRegistry($app) : null;
            $config['registry_auth'] = $registry ? ContainerSpec::registryAuth(BuildService::registryHost($registry), $registry->username, $registry->credentials) : null;
        }
        $this->phase($job, 'deploy');
        $this->dispatchDeploy($deployment, $app, $server, $config, $job);
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
            'pull' => $config['pull'] ?? 'always',
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
                $this->jobs->fail($job, $job->error ?? 'Deployment failed and was rolled back.', null, ['deployment_id' => $deployment->id, 'logs_tail' => $deployment->logs_tail]);
                $this->notifyFailed($app, 'rollback', ($job->error ?? 'Deployment failed').($command->status === 'succeeded' ? ' The previous release was restored.' : ' The rollback failed too.'));
            }

            return;
        }

        try {
            match ($command->type) {
                'image.build' => $this->onBuildFinished($command, $job, $deployment, $app),
                'image.scan' => $this->onScanFinished($command, $job, $deployment, $app),
                'container.healthcheck' => $this->onHealthFinished($command, $job, $deployment, $app),
                default => $this->onDeployCommandFinished($command, $job, $deployment, $app),
            };
        } catch (ApiException $e) {
            $this->failDeployment($deployment, $job, $e->getMessage());
        }
    }

    private function onDeployCommandFinished(AgentCommand $command, Job $job, Deployment $deployment, Application $app): void
    {
        $customer = $app->kind === 'customer';
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

        // UHOST-006: real health check by the agent before the release goes live.
        $release = $deployment->rollback_of_release_id ? Release::find($deployment->rollback_of_release_id) : null;
        $port = $release?->config['port'] ?? $app->port;
        $path = $app->health_path ?: config('platform.builds.health_path');
        $timeout = $app->health_timeout ?: config('platform.builds.health_timeout');
        $this->phase($job, 'health');
        $this->jobs->stage($job, 'health_check', 'running', $port ? "GET :{$port}{$path} (up to {$timeout}s)" : "Waiting for the container to stay up (up to {$timeout}s)");
        $this->bus->dispatch($command->server, 'container.healthcheck', array_filter([
            'container_id' => $job->payload['container_name'] ?? $command->payload['name'] ?? $app->containerName(),
            'port' => $port ? (int) $port : null,
            'path' => $path,
            'timeout_s' => (int) $timeout,
            'log_lines' => 50,
        ], fn ($v) => $v !== null), $job);
    }

    private function onHealthFinished(AgentCommand $command, Job $job, Deployment $deployment, Application $app): void
    {
        $customer = $app->kind === 'customer';
        if ($command->status !== 'succeeded') {
            $logs = (string) ($command->output['logs'] ?? '');
            $deployment->update(['logs_tail' => $logs !== '' ? array_slice(explode("\n", rtrim($logs)), -50) : []]);
            $this->jobs->stage($job, 'health_check', 'failed', $command->message ?: 'Health check failed');
            $name = $job->payload['container_name'] ?? $app->containerName();
            $replaces = $job->payload['replaces'] ?? [];
            if ($replaces) {
                // Rolling / blue-green: the previous containers never stopped; drop the new one.
                $this->bus->dispatch($command->server, 'container.remove', ['container_id' => $name, 'keep_volumes' => true, 'force' => true]);
                $this->jobs->stage($job, 'rollback', 'succeeded', 'Previous release kept serving; removed '.$name);
                $deployment->update(['status' => 'rolled_back', 'finished_at' => now()]);
                $app->update(['status' => 'running']);
                $this->jobs->fail($job, $command->message ?: 'Health check failed.', null, ['deployment_id' => $deployment->id, 'logs_tail' => $deployment->logs_tail]);

                return;
            }
            if (! $app->current_release_id) {
                // UHOST-006: stop the failing container (kept for inspection) and show the logs.
                $this->bus->dispatch($command->server, 'container.stop', ['container_id' => $name, 'timeout' => 5]);
            }
            $this->failDeployment($deployment, $job, $command->message ?: 'Health check failed.', $command->server_id);

            return;
        }
        $out = (array) ($command->output ?? []);
        $this->jobs->stage($job, 'health_check', 'succeeded', match ($out['method'] ?? null) {
            'http' => 'GET '.($out['url'] ?? '').' → '.($out['status'] ?? ''),
            'docker' => 'Docker health check: healthy',
            default => 'Container is running',
        });

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
        $deployment->refresh();
        app(EmailTemplateService::class)->notifyOrganization($app->organization_id, 'deploy_succeeded', [
            'app' => $app->name, 'version' => 'v'.(Release::find($deployment->release_id)?->version ?? ''), 'url' => (string) $this->primaryUrl($app),
            'duration' => $deployment->started_at ? $deployment->started_at->diffForHumans(now(), true) : '—',
        ]);
        // AV-10: the deployed files are the new integrity baseline. Never fail a deployment over it.
        try {
            app(IntegrityService::class)->afterDeployment($app);
        } catch (\Throwable) {
        }
        // WP-010: a new WordPress staging site is filled from its live site once it runs.
        try {
            app(ScalingService::class)->afterDeployment($app);
            app(WordPressService::class)->afterDeployment($app);
            app(MigrationService::class)->afterDeployment($app);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyFailed(Application $app, string $stage, string $error): void
    {
        app(EmailTemplateService::class)->notifyOrganization($app->organization_id, 'deploy_failed', [
            'app' => $app->name, 'stage' => $stage, 'error' => $error,
            'deployment_url' => rtrim((string) (config('platform.panel_url') ?: config('app.url')), '/').'/admin/apps/'.$app->id,
        ]);
    }

    private function failDeployment(Deployment $deployment, Job $job, string $error, ?string $serverId = null): void
    {
        $app = $deployment->application;
        AgentCommand::allTenants()->where('job_id', $job->id)->where('status', 'queued')
            ->update(['status' => 'failed', 'message' => 'Cancelled: an earlier step failed.', 'finished_at' => now()]);
        $previous = $app->current_release_id ? Release::find($app->current_release_id) : null;
        $server = $serverId ? Server::allTenants()->find($serverId) : $app->server;

        // Roll back to the last good release when the old container was replaced.
        // Only when this job touched containers (a failed build or scan leaves the running release alone).
        $touched = AgentCommand::allTenants()->where('job_id', $job->id)->whereIn('type', ['container.create', 'container.remove'])->exists();
        if ($previous && $server && $touched && empty($job->payload['replaces'])) {
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
        $this->notifyFailed($app, (string) ($job->payload['phase'] ?? 'deploy'), $error);
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
            'image' => $source?->image ?? $deployment->image ?? $app->image,
            'digest' => $source?->digest ?? $deployment->image_digest ?? ($app->source['digest'] ?? null),
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
        // ENV-3: shared variables and ${{service.KEY}} references, resolved at release time.
        [$env, $warnings] = app(EnvironmentService::class)->resolve($app, $env);
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
            'env_warnings' => $warnings,
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
