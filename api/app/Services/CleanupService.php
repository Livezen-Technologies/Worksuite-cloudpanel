<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Alert;
use App\Models\Application;
use App\Models\CleanupRun;
use App\Models\Job;
use App\Models\NotificationChannel;
use App\Models\Release;
use App\Models\Server;
use App\Models\User;

/**
 * Server cleanup (CLN-1..5): policy levels, runs when disk use crosses a
 * threshold, the last good releases of every app protected, a run history,
 * and an alert when a cleanup fails or does not bring the disk back down.
 */
class CleanupService
{
    public const LEVELS = ['safe', 'standard', 'aggressive'];

    public const DEFAULTS = ['auto' => true, 'level' => 'safe', 'disk_threshold' => 85, 'keep_releases' => 2];

    /** Minutes between automatic runs on one server. */
    public const COOLDOWN = 60;

    public function __construct(private CommandBus $bus, private JobService $jobs, private NotificationService $notifications) {}

    public function policy(Server $server): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($server->cleanup ?? [], self::DEFAULTS));
    }

    /** Images of the last `keep_releases` releases of each app on the server, plus what each app runs now. */
    public function protectedImages(Server $server): array
    {
        $keep = $this->policy($server)['keep_releases'];
        $apps = Application::allTenants()->where('server_id', $server->id)->get(['id', 'image']);
        $images = $apps->pluck('image')->all();
        foreach ($apps as $app) {
            Release::allTenants()->where('application_id', $app->id)->where('status', '!=', 'failed')
                ->orderByDesc('version')->limit($keep)->get(['image', 'digest'])
                ->each(function ($r) use (&$images) {
                    $images[] = $r->image;
                    $images[] = $r->digest;
                });
        }

        return array_values(array_unique(array_filter($images)));
    }

    public function run(Server $server, string $level, string $trigger = 'manual', ?User $user = null, bool $dryRun = false): CleanupRun
    {
        if (! in_array($level, self::LEVELS, true)) {
            throw ApiException::validation(['level' => ['Unknown cleanup level.']]);
        }
        if (! $server->isOnline()) {
            throw ApiException::conflict("Server {$server->name} is offline.");
        }
        $job = $this->jobs->create('cleanup', ['execute'], $server, ['level' => $level, 'trigger' => $trigger], $user?->id, $server->organization_id);
        $run = CleanupRun::create([
            'organization_id' => $server->organization_id, 'server_id' => $server->id, 'job_id' => $job->id,
            'level' => $level, 'trigger' => $dryRun ? 'preview' : $trigger, 'disk_pct' => $server->disk_pct, 'user_id' => $user?->id,
        ]);
        $this->bus->dispatch($server, 'server.cleanup', ['level' => $level, 'protect' => $this->protectedImages($server), 'dry_run' => $dryRun], $job);

        return $run;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $run = CleanupRun::allTenants()->where('job_id', $job->id)->first();
        $out = (array) ($command->output ?? []);
        if ($command->status !== 'succeeded') {
            $run?->update(['status' => 'failed', 'message' => $command->message ?: 'Cleanup failed', 'result' => $out]);
            $this->jobs->fail($job, $command->message ?: 'Cleanup failed.', 'execute');
            if ($run && $run->trigger !== 'preview') {
                $this->alert($run->server, 'Cleanup failed on '.$run->server->name, $command->message ?: 'The agent reported a failure.', 'critical');
            }

            return;
        }
        $freed = (int) ($out['freed_bytes'] ?? 0);
        $summary = sprintf('%s: %s freed, %d images, %d containers removed, %d protected',
            ucfirst((string) $run?->level), self::bytes($freed), count((array) ($out['images'] ?? [])) + (int) ($out['dangling_images'] ?? 0),
            (int) ($out['containers'] ?? 0), count((array) ($out['protected'] ?? [])));
        $run?->update(['status' => 'succeeded', 'freed_bytes' => $freed, 'result' => $out, 'message' => $summary]);
        $this->jobs->succeed($job, $out + ['message' => $summary]);
    }

    /**
     * Scheduler entry: a cleanup on every server whose disk use is at or above
     * its threshold, at most once per cooldown; when the last automatic run
     * did not bring the disk under the threshold, an alert instead.
     */
    public function evaluate(): int
    {
        $started = 0;
        foreach (Server::allTenants()->where('maintenance', false)->whereNotNull('disk_pct')->get() as $server) {
            $policy = $this->policy($server);
            if (! $policy['auto'] || $server->disk_pct < $policy['disk_threshold'] || ! $server->isOnline()) {
                continue;
            }
            $last = CleanupRun::allTenants()->where('server_id', $server->id)->where('trigger', 'disk')->latest('id')->first();
            if ($last && $last->created_at->gt(now()->subMinutes(self::COOLDOWN))) {
                if ($last->status === 'succeeded' && $last->updated_at->lt(now()->subMinutes(5))) {
                    $this->alert($server, "Disk still at {$server->disk_pct}% on {$server->name} after cleanup",
                        "A {$last->level} cleanup freed ".self::bytes((int) $last->freed_bytes).' but disk use is above the '.$policy['disk_threshold'].'% threshold. Try a stronger cleanup level or add disk.', 'warning');
                }

                continue;
            }
            $this->run($server, $policy['level'], 'disk');
            $started++;
        }

        return $started;
    }

    /** One open alert per server and title. */
    private function alert(Server $server, string $title, string $message, string $severity): void
    {
        $exists = Alert::allTenants()->where('resource_type', 'server')->where('resource_id', $server->id)->where('title', $title)->where('status', 'open')->exists();
        if ($exists) {
            return;
        }
        $alert = Alert::create(['organization_id' => $server->organization_id, 'resource_type' => 'server', 'resource_id' => $server->id, 'severity' => $severity, 'title' => $title, 'message' => $message, 'value' => $server->disk_pct]);
        foreach (NotificationChannel::allTenants()->where('organization_id', $server->organization_id)->where('enabled', true)->get() as $channel) {
            $this->notifications->send($channel, '['.strtoupper($severity)."] {$title}", $message, ['alert_id' => $alert->id]);
        }
    }

    public static function bytes(int $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $u) {
            if ($b < 1024 || $u === 'GB') {
                return ($u === 'B' ? $b : round($b, 1)).' '.$u;
            }
            $b /= 1024;
        }

        return "{$b} B";
    }
}
