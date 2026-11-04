<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Job;
use App\Models\Server;
use App\Models\Volume;

/**
 * Per-server antivirus controls: real-time scanning of written files (AV-2) and
 * outbound abuse limits (AV-12). Settings live on the server and are pushed to the
 * agent when changed and again hourly, since kernel rules and clamonacc do not survive a reboot.
 */
class ServerSecurityService
{
    public const DEFAULTS = ['realtime' => false, 'egress' => ['enabled' => false, 'block_mining' => true, 'smtp_per_minute' => 30]];

    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit) {}

    public static function settings(Server $server): array
    {
        return array_replace_recursive(self::DEFAULTS, $server->security ?? []);
    }

    public function update(Server $server, array $changes): array
    {
        $before = self::settings($server);
        $after = array_replace_recursive($before, $changes);
        $server->update(['security' => $after]);
        $this->audit->log('servers.security_updated', 'servers', $server->id, ['from' => $before, 'to' => $after]);
        $this->apply($server);

        return $after;
    }

    /** Sends both settings to the agent. */
    public function apply(Server $server): void
    {
        if (! $server->isOnline()) {
            throw ApiException::conflict('The server is offline; the settings are saved and will be applied when it is back.');
        }
        $s = self::settings($server);
        $this->bus->dispatch($server, 'egress.apply', [
            'enabled' => $s['egress']['enabled'], 'block_mining' => (bool) $s['egress']['block_mining'],
            'smtp_per_minute' => (int) $s['egress']['smtp_per_minute'], 'pool_addresses' => config('platform.antivirus.pool_addresses'),
        ]);
        $this->bus->dispatch($server, 'av.realtime', ['enabled' => $s['realtime'], 'volumes' => $this->volumes($server)]);
    }

    /** Hourly, so a rebooted server is protected again soon: every online server with something switched on. */
    public function reapplyAll(): int
    {
        $n = 0;
        foreach (Server::allTenants()->whereNotNull('security')->get() as $server) {
            $s = self::settings($server);
            if (($s['realtime'] || $s['egress']['enabled']) && $server->isOnline()) {
                $this->apply($server);
                $n++;
            }
        }

        return $n;
    }

    /** Every 5 minutes: collect real-time detections from servers that watch their volumes. */
    public function collectEvents(): int
    {
        $n = 0;
        foreach (Server::allTenants()->whereNotNull('security')->get() as $server) {
            if (self::settings($server)['realtime'] && $server->isOnline()) {
                $job = $this->jobs->create('av.events', ['execute'], $server, [], null, $server->organization_id);
                $this->bus->dispatch($server, 'av.events', ['volumes' => $this->volumes($server)], $job);
                $n++;
            }
        }

        return $n;
    }

    private function volumes(Server $server): array
    {
        return Volume::allTenants()->where('server_id', $server->id)->where('status', 'active')->where('type', '!=', 'nfs')->pluck('name')->all();
    }
}
