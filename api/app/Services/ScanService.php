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

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $image = Image::find($job->payload['image_id'] ?? $job->resource_id);
        if ($command->status !== 'succeeded') {
            $image?->update(['scan_status' => 'failed']);
            $this->jobs->fail($job, $command->message ?: 'Scan failed.', 'scan');

            return;
        }
        $result = ScanReport::fromAgent($command->output);
        $image?->update(['scan_status' => 'scanned', 'scan_result' => $result, 'scanned_at' => now()]);
        $this->jobs->stage($job, 'scan', 'succeeded', ScanReport::summary($result));
        $this->jobs->succeed($job, $result);
    }

    /** Record a deployment scan on the matching image row of that server, if the heartbeat has reported it. */
    public function recordOnImage(Server $server, string $reference, array $result): void
    {
        $image = Image::allTenants()->where('server_id', $server->id)->get()
            ->first(fn (Image $i) => in_array($reference, $i->tags ?? [], true) || $i->docker_id === $reference || ($i->digest && str_ends_with($reference, $i->digest)));
        $image?->update(['scan_status' => 'scanned', 'scan_result' => $result, 'scanned_at' => now()]);
    }
}
