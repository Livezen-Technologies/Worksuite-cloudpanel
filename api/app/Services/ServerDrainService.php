<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Server;
use App\Support\Tenant;

/**
 * Moves stateless apps off a server (before maintenance or decommissioning) and says what
 * still lives there. Apps with a data volume and databases keep their data on this server's
 * disk, so they are listed rather than moved: move them with a backup and restore.
 */
class ServerDrainService
{
    public function __construct(private DeploymentService $deployments, private AuditLogger $audit) {}

    /** @return array{apps: array, databases: array, volumes: int} what still depends on the server */
    public function residents(Server $server): array
    {
        $apps = Application::allTenants()->where('server_id', $server->id)->get();

        return [
            'apps' => $apps->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'organization_id' => $a->organization_id, 'stateful' => (bool) $a->volume_path])->values()->all(),
            'databases' => \App\Models\Database::allTenants()->where('server_id', $server->id)->get(['id', 'name', 'engine'])->toArray(),
            'volumes' => \App\Models\Volume::allTenants()->where('server_id', $server->id)->count(),
        ];
    }

    /** @return array{moving: array, kept: array} */
    public function drain(Server $server, ?\App\Models\User $user): array
    {
        $server->update(['maintenance' => true]);   // placement never picks it while it drains
        $moving = $kept = [];
        foreach (Application::allTenants()->where('server_id', $server->id)->get() as $app) {
            if ($app->volume_path) {
                $kept[] = ['name' => $app->name, 'reason' => 'Has a data volume on this server; move it with a backup and restore.'];

                continue;
            }
            try {
                $deployment = app(Tenant::class)->as($app->organization_id, function () use ($app, $user) {
                    $app->update(['server_id' => null]);   // the scheduler places it on another healthy server

                    return $this->deployments->start($app->fresh(), $user, 'manual');
                });
                $moving[] = ['name' => $app->name, 'deployment_id' => $deployment->id];
            } catch (ApiException $e) {
                $app->update(['server_id' => $server->id]);
                $kept[] = ['name' => $app->name, 'reason' => $e->getMessage()];
            }
        }
        foreach ($this->residents($server)['databases'] as $db) {
            $kept[] = ['name' => $db['name'], 'reason' => 'Database: move it with a backup and restore.'];
        }
        $this->audit->log('server.drain', 'servers', $server->id, ['moving' => count($moving), 'kept' => count($kept)]);

        return compact('moving', 'kept');
    }
}
