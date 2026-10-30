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
    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit, private NotificationService $notifications) {}

    /** AV-3 settings of an organisation, over the platform defaults. */
    public static function settings(?string $orgId): array
    {
        $org = $orgId ? \App\Models\Organization::find($orgId) : null;

        return array_replace(config('platform.default_settings.antivirus'), $org?->settings['antivirus'] ?? []);
    }

    /**
     * AV-3: runs every 15 minutes and starts the full scans whose time has come, by the
     * schedule of the organisation that owns each volume. A volume is scanned at most
     * once per day (daily) or week (weekly), however often this runs.
     */
    public function scanDue(?\Carbon\CarbonInterface $now = null): int
    {
        $now ??= now();
        $count = 0;
        foreach (Volume::allTenants()->where('status', 'active')->where('type', '!=', 'nfs')->get() as $volume) {
            $owner = $this->owner($volume);
            $s = self::settings($owner);
            if ($s['schedule'] === 'off' || $now->format('H:i') < $s['time'] || ($s['schedule'] === 'weekly' && (int) $now->dayOfWeek !== (int) $s['day'])) {
                continue;
            }
            $period = $s['schedule'] === 'weekly' ? $now->format('o-\WW') : $now->toDateString();
            $server = Server::allTenants()->find($volume->server_id);
            if (! $server || ! $server->isOnline() || ! \Illuminate\Support\Facades\Cache::add("av-scheduled:{$volume->id}:{$period}", true, now()->addDays(8))) {
                continue;
            }
            $this->scan($volume);
            $count++;
        }

        return $count;
    }

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
        if (($job->payload['action'] ?? null) === 'clean') {
            $this->cleanStep($command, $job);

            return;
        }
        if ($command->type === 'av.events') {
            $this->realtimeEvents($command, $job);

            return;
        }
        if ($command->type !== 'av.scan') {
            $this->resolveCommand($command, $job);

            return;
        }
        if ($command->status !== 'succeeded') {
            $this->jobs->fail($job, (string) ($command->message ?: 'The scan failed.'), 'execute');

            return;
        }
        $volume = Volume::allTenants()->find($job->payload['volume_id'] ?? null);
        $new = $this->recordThreats($command->server_id, $volume, (array) ($command->output['threats'] ?? []), $job->organization_id);
        $this->jobs->succeed($job, ['threats' => count((array) ($command->output['threats'] ?? [])), 'new' => $new]);
        if ($new > 0 && $volume) {
            $this->notifyDetections($this->owner($volume), $volume, $new);
        }
    }

    /** AV-2: detections clamonacc logged as files were written, grouped by volume. */
    private function realtimeEvents(AgentCommand $command, Job $job): void
    {
        if ($command->status !== 'succeeded') {
            $this->jobs->fail($job, (string) ($command->message ?: 'Reading real-time detections failed.'), 'execute');

            return;
        }
        $total = 0;
        foreach (collect((array) ($command->output['threats'] ?? []))->groupBy('volume') as $name => $threats) {
            $volume = Volume::allTenants()->where('server_id', $command->server_id)->where('name', $name)->first();
            if (! $volume) {
                continue;
            }
            $owner = $this->owner($volume);
            $allowed = AntivirusAllow::allTenants()->where('organization_id', $owner)->pluck('sha256')->all();
            $new = $this->recordThreats($command->server_id, $volume, $threats->reject(fn ($t) => in_array($t['sha256'], $allowed, true))->all(), $owner);
            if ($new) {
                $this->notifyDetections($owner, $volume, $new);
            }
            $total += $new;
        }
        $this->jobs->succeed($job, ['new' => $total]);
    }

    /** Turns reported infected files into threats, skipping ones already open. Returns how many are new. */
    private function recordThreats(string $serverId, ?Volume $volume, array $threats, ?string $fallbackOrg): int
    {
        $new = 0;
        foreach ($threats as $t) {
            $owner = $volume ? $this->owner($volume) : $fallbackOrg;
            $open = Threat::allTenants()->where('server_id', $serverId)->where('volume_name', $volume?->name)
                ->where('path', $t['path'])->where('sha256', $t['sha256'])->whereIn('status', ['infected', 'quarantined'])->exists();
            if ($open) {
                continue; // already known; a weekly scan must not duplicate it
            }
            $threat = Threat::allTenants()->create([
                'organization_id' => $owner, 'server_id' => $serverId, 'volume_id' => $volume?->id, 'volume_name' => $volume?->name ?? '',
                'path' => $t['path'], 'signature' => $t['signature'], 'sha256' => $t['sha256'], 'size' => (int) ($t['size'] ?? 0),
                'status' => 'infected', 'detected_at' => now(),
            ]);
            $new++;
            if (config('platform.antivirus.auto_quarantine')) {
                $this->dispatchAction($threat, 'quarantine');
            }
        }

        return $new;
    }

    /** AV-14: one message per scan with new detections, to the owning organisation's channels. */
    private function notifyDetections(string $orgId, Volume $volume, int $new): void
    {
        if (! self::settings($orgId)['notify']) {
            return;
        }
        $quarantined = config('platform.antivirus.auto_quarantine') ? ' They were quarantined automatically.' : ' Review them under Antivirus.';
        $title = $new === 1 ? "Malware found on {$volume->name}" : "{$new} malware detections on {$volume->name}";
        foreach (\App\Models\NotificationChannel::allTenants()->where('organization_id', $orgId)->where('enabled', true)->get() as $channel) {
            try {
                $this->notifications->send($channel, $title, "A scan of {$volume->name} found {$new} new infected file".($new === 1 ? '' : 's').'.'.$quarantined, ['volume' => $volume->name, 'new' => $new]);
            } catch (\Throwable) {
                // a failing channel must not lose the scan result
            }
        }
    }

    /**
     * AV-13: a 0–100 score per volume and for the organisation, from what the panel knows:
     * open and undecided threats, how recently the volume was scanned, and the security
     * settings. Each deduction comes with the fix that removes it.
     */
    public function score(): array
    {
        $orgId = app(Tenant::class)->id();
        $s = self::settings($orgId);
        $org = $orgId ? \App\Models\Organization::find($orgId) : null;
        $orgFixes = [];
        if ($s['schedule'] === 'off') {
            $orgFixes[] = ['points' => 10, 'fix' => 'Turn scheduled scans back on.'];
        }
        if (! ($org?->setting('security.require_mfa') ?? false)) {
            $orgFixes[] = ['points' => 10, 'fix' => 'Require two-factor sign-in for everyone in the organisation.'];
        }
        if (! config('platform.crowdsec.enabled')) {
            $orgFixes[] = ['points' => 5, 'fix' => 'Enable the CrowdSec firewall so brute-force sources are banned.'];
        }
        if (config('platform.waf.enabled')) {
            $open = \App\Models\Domain::query()->whereNotNull('application_id')->get()->filter(fn ($d) => ($d->options['waf']['mode'] ?? 'off') !== 'block')->count();
            if ($open) {
                $orgFixes[] = ['points' => 5, 'fix' => "Set the web application firewall to Block on {$open} domain".($open === 1 ? '' : 's').'.'];
            }
        }
        $critical = \App\Models\Image::query()->get()->filter(fn ($i) => ($i->scan_result['critical'] ?? 0) > 0)->count();
        if ($critical) {
            $orgFixes[] = ['points' => min(20, 10 * $critical), 'fix' => "Rebuild or update the {$critical} image".($critical === 1 ? '' : 's').' with critical vulnerabilities.'];
        }
        $volumes = Volume::query()->where('type', '!=', 'nfs')->get()->map(function (Volume $v) use ($orgFixes) {
            $fixes = $orgFixes;
            $open = Threat::where('volume_id', $v->id)->where('status', 'infected')->count();
            $held = Threat::where('volume_id', $v->id)->where('status', 'quarantined')->count();
            if ($open) {
                $fixes[] = ['points' => min(60, 30 * $open), 'fix' => "Quarantine or clean the {$open} infected file".($open === 1 ? '' : 's').'.'];
            }
            if ($held) {
                $fixes[] = ['points' => min(15, 5 * $held), 'fix' => "Decide on the {$held} quarantined file".($held === 1 ? '' : 's').': delete, clean or mark as not a threat.'];
            }
            $changed = \App\Models\IntegrityChange::where('volume_id', $v->id)->where('status', 'open')->count();
            if ($changed) {
                $fixes[] = ['points' => min(20, 10 * $changed), 'fix' => "Review the {$changed} code file".($changed === 1 ? '' : 's').' changed outside a deployment.'];
            }
            $last = Job::where('type', 'av.scan')->where('resource_type', 'Volume')->where('resource_id', $v->id)->where('status', 'succeeded')->max('finished_at');
            if (! $last || \Illuminate\Support\Carbon::parse($last)->lt(now()->subDays(8))) {
                $fixes[] = ['points' => 15, 'fix' => $last ? 'Scan again: the last scan is over a week old.' : 'Run a first scan.'];
            }

            return ['volume_id' => $v->id, 'volume' => $v->name, 'score' => max(0, 100 - array_sum(array_column($fixes, 'points'))), 'fixes' => $fixes,
                'last_scan_at' => $last ? \Illuminate\Support\Carbon::parse($last)->toIso8601ZuluString() : null];
        })->sortBy('score')->values();

        return [
            'score' => $volumes->isEmpty() ? max(0, 100 - array_sum(array_column($orgFixes, 'points'))) : (int) round($volumes->avg('score')),
            'lowest' => $volumes->first()['score'] ?? null, 'volumes' => $volumes->all(), 'fixes' => $orgFixes,
        ];
    }

    /** AV-5 / AV-7: quarantine, restore, delete or mark as not a threat. */
    public function act(Threat $threat, string $action, ?string $userId = null): ?Job
    {
        $allowed = [
            'quarantine' => ['infected', 'restored'], 'restore' => ['quarantined'], 'clean' => ['infected', 'quarantined', 'restored'],
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

        if ($action === 'clean') {
            return $this->clean($threat, $userId);
        }

        return $this->dispatchAction($threat, $action, $userId);
    }

    /** A WordPress core file: the site root it sits in and its path inside WordPress. */
    public const CORE_FILE = '#^(?<root>(?:.*/)?)(?<rel>wp-(?:admin|includes)/.+|wp-[a-z-]+\.php|index\.php|xmlrpc\.php)$#';

    /**
     * AV-6: replaces an infected WordPress core file with the official copy for the site's
     * version, checked against WordPress.org's published md5 before it is written. Runs as
     * one job in two agent steps: read wp-includes/version.php, then write the file back.
     * Anything else must come back from a backup.
     */
    private function clean(Threat $threat, ?string $userId): Job
    {
        if (! preg_match(self::CORE_FILE, $threat->path, $m)) {
            throw ApiException::conflict('Only WordPress core files can be cleaned automatically. Restore this file from a backup.');
        }
        $server = Server::allTenants()->find($threat->server_id);
        if (! $server || ! $server->isOnline()) {
            throw ApiException::conflict('The server is offline.');
        }
        $job = $this->jobs->create('av.action', ['execute'], $threat, ['threat_id' => $threat->id, 'action' => 'clean', 'root' => $m['root'], 'rel' => $m['rel']], $userId, $threat->organization_id);
        $this->bus->dispatch($server, 'fs.read', ['volume' => $threat->volume_name, 'path' => $m['root'].'wp-includes/version.php'], $job);

        return $job;
    }

    private function cleanStep(AgentCommand $command, Job $job): void
    {
        $threat = Threat::allTenants()->find($job->payload['threat_id']);
        $dir = FileService::TRANSFER_DIR.'/'.$job->id;
        if ($command->status !== 'succeeded') {
            \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory($dir);
            $this->jobs->fail($job, $command->type === 'fs.read' ? 'Could not read wp-includes/version.php, so the WordPress version is unknown.' : (string) ($command->message ?: 'Writing the clean file failed.'), 'execute');

            return;
        }
        if ($command->type === 'fs.upload') {
            \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory($dir);
            $threat?->update(['status' => 'cleaned', 'resolved_at' => now(), 'resolved_by' => $job->user_id]);
            $this->jobs->succeed($job, ['path' => $threat?->path, 'version' => $job->payload['version'] ?? null]);

            return;
        }
        try {
            if (! preg_match('/\$wp_version\s*=\s*[\'"]([0-9][0-9A-Za-z.\-]*)[\'"]/', (string) ($command->output['content'] ?? ''), $v)) {
                throw new \RuntimeException('wp-includes/version.php does not name a WordPress version.');
            }
            $rel = $job->payload['rel'];
            $sums = \Illuminate\Support\Facades\Http::timeout(20)->get('https://api.wordpress.org/core/checksums/1.0/', ['version' => $v[1], 'locale' => 'en_US'])->json('checksums');
            $md5 = is_array($sums) ? ($sums[$rel] ?? null) : null;
            if (! $md5) {
                throw new \RuntimeException("{$rel} is not part of WordPress {$v[1]}, so there is no official copy to restore.");
            }
            $file = \Illuminate\Support\Facades\Http::timeout(30)->get("https://core.svn.wordpress.org/tags/{$v[1]}/{$rel}");
            if (! $file->successful() || md5($file->body()) !== $md5) {
                throw new \RuntimeException("The official {$rel} for WordPress {$v[1]} could not be downloaded or did not match its checksum.");
            }
        } catch (\Throwable $e) {
            $this->jobs->fail($job, $e->getMessage(), 'execute');

            return;
        }
        \Illuminate\Support\Facades\Storage::disk('local')->put($dir.'/0', $file->body());
        $job->update(['payload' => $job->payload + ['version' => $v[1]]]);
        $url = rtrim(config('backups.agent_api_url'), '/').\Illuminate\Support\Facades\URL::temporarySignedRoute('agent.file-transfer-get', now()->addHours(6), ['id' => $job->id, 'n' => 0], absolute: false);
        $this->bus->dispatch(Server::allTenants()->find($command->server_id), 'fs.upload', [
            'volume' => $threat->volume_name, 'files' => [['path' => $job->payload['root'].$rel, 'url' => $url]], 'overwrite' => true,
        ], $job);
        $this->audit->log('antivirus.clean', 'threats', $threat->id, ['path' => $threat->path, 'wordpress' => $v[1], 'md5' => $md5]);
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
