<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Container;
use App\Models\Database;
use App\Models\Job;
use App\Models\Secret;
use App\Models\Server;
use App\Support\Tenant;
use Illuminate\Support\Str;

/** DB-001..003: databases from templates, credentials as secrets injected by reference. */
class DatabaseService implements HandlesCommandResults
{
    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    public function create(array $data): array
    {
        $template = config('platform.database_templates')[$data['engine']] ?? null;
        if (! $template) {
            throw ApiException::validation(['engine' => ['Unknown database engine.']]);
        }
        $server = Server::findOrFail($data['server_id']);
        $version = $data['version'] ?? $template['versions'][0];
        $secret = Secret::create(['name' => $data['name'].'-password', 'value' => Str::password(32, symbols: false)]);
        $database = Database::create([
            'server_id' => $server->id, 'name' => $data['name'], 'engine' => $data['engine'], 'version' => $version,
            'environment' => $data['environment'] ?? 'production', 'secret_id' => $secret->id, 'status' => 'creating',
        ]);
        $secret->update(['owner_type' => 'Database', 'owner_id' => $database->id]);

        $containerName = 'db-'.Str::slug($data['name']);
        $env = [$template['password_env'] => $secret->value];
        $input = [
            'name' => $containerName,
            'image' => $template['image'].':'.$version,
            'env' => $env,
            'read_only_rootfs' => false,
            'mounts' => [['type' => 'volume', 'source' => $containerName.'-data', 'target' => $template['data']]],
            'networks' => [['name' => $data['network'] ?? 'internal']],
            'memory_mb' => $data['memory_mb'] ?? 512,
            'labels' => ['io.dockermgr.database' => $database->id],
        ];
        if ($data['engine'] === 'redis') {
            $input['cmd'] = ['redis-server', '--requirepass', $secret->value, '--appendonly', 'yes'];
        }
        $spec = ContainerSpec::build($input, Tenant::current());
        $job = $this->jobs->create('database.create', ['execute'], $database);
        $this->bus->dispatch($server, 'container.create', $spec, $job);
        $container = Container::create(['server_id' => $server->id, 'name' => $containerName, 'image' => $spec['image'], 'state' => 'creating', 'spec' => ContainerSpec::redact($spec)]);
        $database->update(['container_id' => $container->id]);

        return ['database' => $this->toApi($database->fresh()), 'job_id' => $job->id];
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $database = Database::find($job->resource_id);
        $ok = $command->status === 'succeeded';
        $database?->update(['status' => $ok ? 'running' : 'failed']);
        $ok ? $this->jobs->succeed($job, ['database_id' => $database?->id]) : $this->jobs->fail($job, $command->message ?: 'Database container failed to start', 'execute');
    }

    public function toApi(Database $db): array
    {
        $template = config('platform.database_templates')[$db->engine] ?? [];

        return array_merge($db->toArray(), [
            'port' => $template['port'] ?? null,
            'host' => 'db-'.Str::slug($db->name),
            'credentials_ref' => $db->secret_id ? 'secret://'.$db->secret_id : null,
        ]);
    }
}
