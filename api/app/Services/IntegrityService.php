<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\IntegrityChange;
use App\Models\Job;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Models\Volume;

/**
 * File integrity monitoring (AV-10). The agent keeps a baseline of every code file's
 * hash in each volume; the panel takes one after each deployment and on request, and
 * a daily check reports code added, changed or removed since. Those changes happened
 * outside a deployment, so each is listed until someone accepts it, which takes a
 * fresh baseline.
 */
class IntegrityService implements HandlesCommandResults
{
    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit, private NotificationService $notifications) {}

    public function baseline(Volume $volume, ?string $userId = null): Job
    {
        return $this->dispatch($volume, 'fim.baseline', $userId);
    }

    public function check(Volume $volume, ?string $userId = null): Job
    {
        if (! $volume->integrity_baseline_at) {
            throw ApiException::conflict('This volume has no baseline yet. Take one first; it records the files as they are now.');
        }

        return $this->dispatch($volume, 'fim.check', $userId);
    }

    /** Accepting the open changes takes a new baseline, so the same changes are not reported again. */
    public function accept(Volume $volume, ?string $userId): Job
    {
        $n = IntegrityChange::allTenants()->where('volume_id', $volume->id)->where('status', 'open')
            ->update(['status' => 'accepted', 'resolved_at' => now(), 'resolved_by' => $userId]);
        $this->audit->log('antivirus.integrity_accepted', 'volumes', $volume->id, ['volume' => $volume->name, 'changes' => $n]);

        return $this->baseline($volume, $userId);
    }

    /** After a successful deployment, the app's files become the new baseline. */
    public function afterDeployment(Application $app): void
    {
        if (! $app->volume_path) {
            return;
        }
        $volume = Volume::allTenants()->where('server_id', $app->server_id)->where('name', 'app-'.$app->name.'-data')->first();
        $server = $volume ? Server::allTenants()->find($volume->server_id) : null;
        if ($volume && $server?->isOnline()) {
            $this->baseline($volume);
        }
    }

    /** Daily: every volume with a baseline on an online server. */
    public function checkAll(): int
    {
        $n = 0;
        foreach (Volume::allTenants()->whereNotNull('integrity_baseline_at')->where('type', '!=', 'nfs')->get() as $volume) {
            if (Server::allTenants()->find($volume->server_id)?->isOnline()) {
                $this->dispatch($volume, 'fim.check');
                $n++;
            }
        }

        return $n;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $volume = Volume::allTenants()->find($job->resource_id);
        if ($command->status !== 'succeeded' || ! $volume) {
            $this->jobs->fail($job, (string) ($command->message ?: 'The integrity command failed.'), 'execute');

            return;
        }
        $out = (array) $command->output;
        if ($command->type === 'fim.baseline') {
            $volume->update(['integrity_baseline_at' => now()]);
            $this->jobs->succeed($job, ['files' => $out['files'] ?? 0, 'truncated' => $out['truncated'] ?? false]);

            return;
        }
        $volume->update(['integrity_checked_at' => now()]);
        $owner = $job->organization_id ?? $volume->organization_id;
        $new = 0;
        foreach (['added', 'changed', 'removed'] as $kind) {
            foreach ((array) ($out[$kind] ?? []) as $path) {
                $known = IntegrityChange::allTenants()->where('volume_id', $volume->id)->where('path', $path)->where('change', $kind)->where('status', 'open')->exists();
                if (! $known) {
                    IntegrityChange::allTenants()->create([
                        'organization_id' => $owner, 'volume_id' => $volume->id, 'server_id' => $volume->server_id, 'volume_name' => $volume->name,
                        'path' => $path, 'change' => $kind, 'detected_at' => now(),
                    ]);
                    $new++;
                }
            }
        }
        $this->jobs->succeed($job, ['added' => count($out['added'] ?? []), 'changed' => count($out['changed'] ?? []), 'removed' => count($out['removed'] ?? []), 'new' => $new]);
        if ($new && AntivirusService::settings($owner)['notify']) {
            foreach (NotificationChannel::allTenants()->where('organization_id', $owner)->where('enabled', true)->get() as $channel) {
                try {
                    $this->notifications->send($channel, "Code changed outside a deployment on {$volume->name}",
                        "{$new} code file".($new === 1 ? '' : 's')." changed since the last baseline. Review them under Antivirus → Integrity.", ['volume' => $volume->name, 'new' => $new]);
                } catch (\Throwable) {
                }
            }
        }
    }

    private function dispatch(Volume $volume, string $type, ?string $userId = null): Job
    {
        $server = Server::allTenants()->find($volume->server_id);
        if (! $server || ! $server->isOnline()) {
            throw ApiException::conflict('The volume\'s server is offline.');
        }
        if ($volume->type === 'nfs') {
            throw ApiException::conflict('NFS volumes are monitored on the NFS server, not here.');
        }
        $owner = app(\App\Support\Tenant::class)->id() ?? $volume->organization_id;
        $job = $this->jobs->create('integrity', ['execute'], $volume, ['operation' => $type], $userId, $owner);
        $this->bus->dispatch($server, $type, ['volume' => $volume->name], $job);

        return $job;
    }
}
