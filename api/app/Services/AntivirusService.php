<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\AntivirusAllow;
use App\Models\Application;
use App\Models\Job;
use App\Models\Server;
use App\Models\Threat;
use App\Models\Volume;
use App\Support\Tenant;

/**
 * Antivirus (SRS 4.25). Scans run on the agent with ClamAV (and the YARA rules
 * loaded into it); each infected file becomes a threat in the organisation that
 * owns the files and, with auto-quarantine on, is moved out of the volume at once.
 */
class AntivirusService implements HandlesCommandResults
{
    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit) {}

    /** Queues a scan of one volume. */
    public function scan(Volume $volume, ?string $userId = null): Job
    {
        $server = Server::allTenants()->find($volume->server_id);
        if (! $server || ! $server->isOnline()) {
            throw ApiException::conflict('The volume\'s server is offline, so it cannot be scanned.');
        }
        if ($volume->type === 'nfs') {
            throw ApiException::conflict('NFS volumes are scanned on the NFS server, not here.');
        }
        $owner = $this->owner($volume);
        $job = $this->jobs->create('av.scan', ['execute'], $volume, ['volume_id' => $volume->id], $userId, $owner);
        $allow = AntivirusAllow::allTenants()->where('organization_id', $owner)->pluck('sha256')->all();
        $this->bus->dispatch($server, 'av.scan', ['volume' => $volume->name, 'path' => '', 'allowlist' => $allow], $job);

        return $job;
    }

    /** AV-3: every local volume on every online server. Returns the scans queued. */
    public function scanAll(): int
    {
        $count = 0;
        foreach (Volume::allTenants()->where('status', 'active')->where('type', '!=', 'nfs')->get() as $volume) {
            $server = Server::allTenants()->find($volume->server_id);
            if ($server && $server->isOnline()) {
                $this->scan($volume);
                $count++;
            }
        }

        return $count;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        if ($command->type !== 'av.scan') {
            $this->resolveCommand($command, $job);

            return;
        }
        if ($command->status !== 'succeeded') {
            $this->jobs->fail($job, (string) ($command->message ?: 'The scan failed.'), 'execute');

            return;
        }
        $volume = Volume::allTenants()->find($job->payload['volume_id'] ?? null);
        $new = 0;
        foreach ((array) ($command->output['threats'] ?? []) as $t) {
            $owner = $volume ? $this->owner($volume) : $job->organization_id;
            $open = Threat::allTenants()->where('server_id', $command->server_id)->where('volume_name', $volume?->name)
                ->where('path', $t['path'])->where('sha256', $t['sha256'])->whereIn('status', ['infected', 'quarantined'])->exists();
            if ($open) {
                continue; // already known; a weekly scan must not duplicate it
            }
            $threat = Threat::allTenants()->create([
                'organization_id' => $owner, 'server_id' => $command->server_id, 'volume_id' => $volume?->id, 'volume_name' => $volume?->name ?? '',
                'path' => $t['path'], 'signature' => $t['signature'], 'sha256' => $t['sha256'], 'size' => (int) ($t['size'] ?? 0),
                'status' => 'infected', 'detected_at' => now(),
            ]);
            $new++;
            if (config('platform.antivirus.auto_quarantine')) {
                $this->dispatchAction($threat, 'quarantine');
            }
        }
        $this->jobs->succeed($job, ['threats' => count((array) ($command->output['threats'] ?? [])), 'new' => $new]);
    }

    /** AV-5 / AV-7: quarantine, restore, delete or mark as not a threat. */
    public function act(Threat $threat, string $action, ?string $userId = null): ?Job
    {
        $allowed = [
            'quarantine' => ['infected', 'restored'], 'restore' => ['quarantined'],
            'delete' => ['quarantined'], 'ignore' => ['infected', 'quarantined', 'restored'],
        ];
        if (! in_array($threat->status, $allowed[$action] ?? [], true)) {
            throw ApiException::conflict("A threat that is {$threat->status} cannot be {$action}d.");
        }
        $this->audit->log('antivirus.'.$action, 'threats', $threat->id, ['path' => $threat->path, 'signature' => $threat->signature, 'sha256' => $threat->sha256]);
        if ($action === 'ignore') {
            AntivirusAllow::allTenants()->firstOrCreate(
                ['organization_id' => $threat->organization_id, 'sha256' => $threat->sha256],
                ['reason' => 'Not a threat: '.$threat->signature, 'user_id' => $userId],
            );
            $job = $threat->status === 'quarantined' ? $this->dispatchAction($threat, 'restore', $userId) : null;
            $threat->update(['status' => 'ignored', 'resolved_at' => now(), 'resolved_by' => $userId]);

            return $job;
        }

        return $this->dispatchAction($threat, $action, $userId);
    }

    private function dispatchAction(Threat $threat, string $action, ?string $userId = null): Job
    {
        $server = Server::allTenants()->find($threat->server_id);
        $job = $this->jobs->create('av.action', ['execute'], $threat, ['threat_id' => $threat->id, 'action' => $action], $userId, $threat->organization_id);
        $this->bus->dispatch($server, 'av.'.$action, ['volume' => $threat->volume_name, 'path' => $threat->path, 'id' => $threat->id], $job);

        return $job;
    }

    private function resolveCommand(AgentCommand $command, Job $job): void
    {
        $threat = Threat::allTenants()->find($job->payload['threat_id'] ?? null);
        if ($command->status !== 'succeeded') {
            $this->jobs->fail($job, (string) ($command->message ?: 'The action failed.'), 'execute');

            return;
        }
        if ($threat && $threat->status !== 'ignored') {
            $threat->update(match ($command->type) {
                'av.quarantine' => ['status' => 'quarantined'],
                'av.restore' => ['status' => 'restored', 'resolved_at' => now()],
                'av.delete' => ['status' => 'deleted', 'resolved_at' => now()],
                default => [],
            });
        }
        $this->jobs->succeed($job, (array) $command->output);
    }

    /** Files in an app's volume belong to the app's owner, even on a shared server. */
    private function owner(Volume $volume): string
    {
        if (preg_match('/^app-(.+)-data$/', $volume->name, $m)) {
            $app = Application::allTenants()->where('server_id', $volume->server_id)->where('name', $m[1])->first();
            if ($app) {
                return $app->organization_id;
            }
        }

        return $volume->organization_id ?? app(Tenant::class)->id();
    }
}
