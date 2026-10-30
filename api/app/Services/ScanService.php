<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Contracts\ImageScanner;
use App\Models\AgentCommand;
use App\Models\Image;
use App\Models\Job;
use App\Models\Server;
use App\Support\ScanReport;

/**
 * CVE scanning (IMG-003, SEC-007). The `fake` driver answers in-process; the
 * `trivy` driver sends `image.scan` to the agent on the server holding the image.
 */
class ScanService implements HandlesCommandResults
{
    public function __construct(private ImageScanner $scanner, private CommandBus $bus, private JobService $jobs) {}

    public static function agentDriven(): bool
    {
        return config('platform.drivers.scanner') === 'trivy';
    }

    /** In-process scan (fake driver). */
    public function scanNow(string $reference, ?string $digest): array
    {
        return $this->scanner->scan($reference, $digest) + ['scanner' => 'fake'];
    }

    /** Queue `image.scan` for an image row (Images page "Scan"). */
    public function queueImageScan(Image $image): Job
    {
        $job = $this->jobs->create('image.scan', ['scan'], $image, ['image_id' => $image->id]);
        $image->update(['scan_status' => 'scanning']);
        $this->bus->dispatch($image->server, 'image.scan', ['image' => $image->tags[0] ?? $image->docker_id], $job);

        return $job;
    }

    /** AV-11: queues a rescan of every image not scanned in the last day (Trivy driver only). */
    public function scanStale(): int
    {
        if (! self::agentDriven()) {
            return 0;
        }
        $n = 0;
        foreach (Image::allTenants()->where(fn ($q) => $q->whereNull('scanned_at')->orWhere('scanned_at', '<', now()->subDay()))->where('scan_status', '!=', 'scanning')->get() as $image) {
            if ($image->server?->isOnline()) {
                app(\App\Support\Tenant::class)->as($image->organization_id, fn () => $this->queueImageScan($image));
                $n++;
            }
        }

        return $n;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $image = Image::allTenants()->find($job->payload['image_id'] ?? $job->resource_id);
        $before = (int) ($image?->scan_result['critical'] ?? 0);
        if ($command->status !== 'succeeded') {
            $image?->update(['scan_status' => 'failed']);
            $this->jobs->fail($job, $command->message ?: 'Scan failed.', 'scan');

            return;
        }
        $result = ScanReport::fromAgent($command->output);
        $image?->update(['scan_status' => 'scanned', 'scan_result' => $result, 'scanned_at' => now()]);
        $this->jobs->stage($job, 'scan', 'succeeded', ScanReport::summary($result));
        $this->jobs->succeed($job, $result);
        // AV-14: an image that newly has critical CVEs is news; one that already had them is not.
        if ($image && ($result['critical'] ?? 0) > 0 && $before === 0 && AntivirusService::settings($image->organization_id)['notify']) {
            $name = $image->tags[0] ?? $image->docker_id;
            foreach (\App\Models\NotificationChannel::allTenants()->where('organization_id', $image->organization_id)->where('enabled', true)->get() as $channel) {
                try {
                    app(NotificationService::class)->send($channel, "Critical vulnerabilities in {$name}", ScanReport::summary($result).'. Rebuild or update the image.', ['image' => $name]);
                } catch (\Throwable) {
                }
            }
        }
    }

    /** Record a deployment scan on the matching image row of that server, if the heartbeat has reported it. */
    public function recordOnImage(Server $server, string $reference, array $result): void
    {
        $image = Image::allTenants()->where('server_id', $server->id)->get()
            ->first(fn (Image $i) => in_array($reference, $i->tags ?? [], true) || $i->docker_id === $reference || ($i->digest && str_ends_with($reference, $i->digest)));
        $image?->update(['scan_status' => 'scanned', 'scan_result' => $result, 'scanned_at' => now()]);
    }
}
