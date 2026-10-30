<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Container;
use App\Models\Image;
use App\Models\Job;
use App\Models\Network;
use App\Models\Server;
use App\Models\Volume;
use App\Support\CommandSigner;
use Illuminate\Support\Facades\DB;

/**
 * Queues typed commands for agents, signs them on delivery and applies
 * results. The command id is the operation id: results are idempotent and
 * a command is delivered (sent) at most once.
 */
class CommandBus
{
    public const TYPES = [
        'container.start', 'container.stop', 'container.restart', 'container.pause', 'container.unpause',
        'container.remove', 'container.create', 'container.logs', 'container.prune',
        'image.pull', 'image.remove', 'image.prune', 'network.create', 'network.remove',
        'volume.create', 'volume.remove', 'compose.up', 'compose.down', 'compose.restart',
        'backup.run', 'restore.run', 'backup.test', 'backup.export', 'proxy.config', 'proxy.certs', 'agent.update', 'agent.restart',
        'image.build', 'image.scan', 'container.healthcheck', 'terminal.open',
        'fs.list', 'fs.read', 'fs.write', 'fs.mkdir', 'fs.rename', 'fs.delete', 'fs.upload', 'fs.download',
        'fs.copy', 'fs.move', 'fs.compress', 'fs.extract', 'fs.trash', 'fs.trash_list', 'fs.trash_restore', 'fs.trash_purge', 'fs.chmod', 'fs.chown', 'fs.search', 'fs.scan', 'fs.history_list', 'fs.history_read', 'fs.history_restore',
        'crowdsec.apply', 'crowdsec.alerts', 'av.scan', 'av.quarantine', 'av.restore', 'av.delete',
    ];

    /** Job type => handler class. Others use the generic "all commands done" rule. */
    private const HANDLERS = [
        'deployment' => DeploymentService::class,
        'backup' => BackupService::class,
        'restore' => BackupService::class,
        'backup.test' => BackupService::class,
        'backup.export' => BackupService::class,
        'proxy.certs' => \App\Services\TraefikConfigService::class,
        'compose.deploy' => ComposeService::class,
        'database.create' => DatabaseService::class,
        'image.scan' => ScanService::class,
        'files' => FileService::class,
        'crowdsec.alerts' => FirewallEventService::class,
        'av.scan' => AntivirusService::class,
        'av.action' => AntivirusService::class,
    ];

    public function __construct(private JobService $jobs) {}

    public function dispatch(Server $server, string $type, array $payload, ?Job $job = null): AgentCommand
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown command type {$type}");
        }
        if ($server->maintenance && in_array($type, ['container.create', 'compose.up'], true)) {
            throw ApiException::conflict("Server {$server->name} is in maintenance mode; new deployments are blocked.");
        }

        return AgentCommand::create([
            'organization_id' => $server->organization_id,
            'server_id' => $server->id,
            'job_id' => $job?->id,
            'type' => $type,
            'payload' => $payload,
            'status' => 'queued',
        ]);
    }

    /** Claim queued commands for delivery and return them signed. */
    public function deliver(Server $server, string $hmacKey, int $limit = 20): array
    {
        return DB::transaction(function () use ($server, $hmacKey, $limit) {
            $commands = AgentCommand::allTenants()->where('server_id', $server->id)->where('status', 'queued')
                ->orderBy('created_at')->orderBy('id')->limit($limit)->lockForUpdate()->get();

            return $commands->map(function (AgentCommand $command) use ($hmacKey) {
                $command->issued_at = now()->utc()->format('Y-m-d\TH:i:s\Z');
                $command->status = 'sent';
                $command->sent_at = now();
                $command->save();

                return $this->wire($command, $hmacKey);
            })->all();
        });
    }

    public function wire(AgentCommand $command, string $hmacKey): array
    {
        $payload = $command->payload ?: new \stdClass;

        return [
            'id' => $command->id,
            'type' => $command->type,
            'version' => 1,
            'payload' => $payload,
            'issued_at' => $command->issued_at,
            'signature' => CommandSigner::sign($hmacKey, $command->id, $command->type, $payload, $command->issued_at),
        ];
    }

    /** Apply an agent result; repeated results for a finished command are ignored. */
    public function applyResult(AgentCommand $command, string $status, ?int $progress, ?string $message, mixed $output): AgentCommand
    {
        $finishedNow = DB::transaction(function () use ($command, $status, $progress, $message, $output) {
            $command = AgentCommand::allTenants()->lockForUpdate()->find($command->id);
            if ($command->isFinished()) {
                return false;
            }
            $command->status = $status;
            $command->progress = $status === 'succeeded' ? 100 : ($progress ?? $command->progress);
            $command->message = $message ?? $command->message;
            if ($status === 'running' && $message !== null && $message !== '' && $command->type === 'image.build') {
                // Build log lines arrive as running progress; keep the last 1000 for the pipeline view.
                $out = $command->output ?? [];
                $out['log'] = array_slice(array_merge($out['log'] ?? [], explode("\n", $message)), -1000);
                $command->output = $out;
            }
            if ($output !== null) {
                $command->output = is_array($output) ? $output : ['value' => $output];
            }
            if ($command->isFinished()) {
                $command->finished_at = now();
            }
            $command->save();

            return $command->isFinished();
        });
        $command->refresh();

        $job = $command->job_id ? Job::allTenants()->find($command->job_id) : null;
        if ($job && $status === 'running' && ! $job->isFinished()) {
            $this->jobs->start($job);
        }
        if (! $finishedNow) {
            return $command;
        }

        $this->applySideEffects($command);
        if ($job && ! $job->isFinished()) {
            // Continue the job in the job's organisation (shared-pool servers may belong to another).
            app(\App\Support\Tenant::class)->as($job->organization_id, function () use ($command, $job) {
                $handler = self::HANDLERS[$job->type] ?? null;
                $handler ? app($handler)->onCommandFinished($command, $job) : $this->genericJobUpdate($job);
            });
        }

        return $command;
    }

    /** Generic rule: job progress = finished/total, fails if any command failed. */
    public function genericJobUpdate(Job $job): void
    {
        $commands = AgentCommand::allTenants()->where('job_id', $job->id)->get();
        $finished = $commands->filter->isFinished();
        $failed = $commands->where('status', 'failed');
        if ($finished->count() < $commands->count()) {
            $this->jobs->progress($job, (int) floor($finished->count() / max(1, $commands->count()) * 100));

            return;
        }
        $result = ['commands' => $commands->map(fn ($c) => ['id' => $c->id, 'type' => $c->type, 'status' => $c->status, 'message' => $c->message, 'output' => $c->output])->values()->all()];
        $failed->isNotEmpty()
            ? $this->jobs->fail($job, $failed->first()->message ?: 'Command failed on the agent.', 'execute', $result)
            : $this->jobs->succeed($job, $result);
    }

    /** Optimistic state updates; heartbeats reconcile the truth afterwards. */
    private function applySideEffects(AgentCommand $command): void
    {
        if ($command->status !== 'succeeded') {
            if ($command->type === 'container.create') {
                Container::allTenants()->where('server_id', $command->server_id)->where('name', $command->payload['name'] ?? '')
                    ->whereNull('docker_id')->update(['state' => 'failed']);
            }

            return;
        }
        $p = $command->payload;
        $container = isset($p['container_id']) ? Container::allTenants()->where('server_id', $command->server_id)
            ->where(fn ($q) => $q->where('docker_id', $p['container_id'])->orWhere('name', $p['container_id']))->first() : null;

        match ($command->type) {
            'container.start', 'container.unpause', 'container.restart' => $container?->update(['state' => 'running', 'started_at' => $command->type === 'container.unpause' ? $container->started_at : now()]),
            'container.stop' => $container?->update(['state' => 'exited']),
            'container.pause' => $container?->update(['state' => 'paused']),
            'container.remove' => $container?->delete(),
            'container.logs' => $container?->update(['last_logs' => is_array($command->output) ? ($command->output['logs'] ?? json_encode($command->output)) : null, 'logs_fetched_at' => now()]),
            'container.create' => Container::allTenants()->where('server_id', $command->server_id)->where('name', $p['name'] ?? '')->whereNull('docker_id')
                ->update(['docker_id' => $command->output['container_id'] ?? null, 'state' => ($p['start'] ?? true) ? 'running' : 'created', 'started_at' => now()]),
            'network.create' => Network::allTenants()->where('server_id', $command->server_id)->where('name', $p['name'])->update(['status' => 'active', 'docker_id' => $command->output['network_id'] ?? null]),
            'network.remove' => Network::allTenants()->where('server_id', $command->server_id)->where(fn ($q) => $q->where('name', $p['name'] ?? null)->orWhere('docker_id', $p['network_id'] ?? null))->delete(),
            'volume.create' => Volume::allTenants()->where('server_id', $command->server_id)->where('name', $p['name'])->update(['status' => 'active']),
            'volume.remove' => Volume::allTenants()->where('server_id', $command->server_id)->where('name', $p['name'])->delete(),
            'image.remove' => Image::allTenants()->where('server_id', $command->server_id)->where(fn ($q) => $q->where('docker_id', $p['image'])->orWhere('repository', $p['image']))->delete(),
            default => null,
        };
    }
}
