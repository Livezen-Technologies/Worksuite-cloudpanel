<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Job;
use App\Models\Volume;

/**
 * File manager (SRS 4.17). Each operation is one fs.* agent command on the
 * volume's server; the agent confines it to the volume (FM-013). Results come
 * back on the job, so the browser polls GET /jobs/{id} as for any other job.
 */
class FileService implements HandlesCommandResults
{
    /** Operation => agent command type. */
    public const OPERATIONS = [
        'list' => 'fs.list', 'read' => 'fs.read', 'write' => 'fs.write',
        'mkdir' => 'fs.mkdir', 'rename' => 'fs.rename', 'delete' => 'fs.delete',
    ];

    /** Operations that change files; each is audited with its path (FM-014). */
    private const WRITES = ['write', 'mkdir', 'rename', 'delete'];

    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit) {}

    public function run(Volume $volume, string $operation, array $payload): Job
    {
        $server = $volume->server;
        if (! $server || ! $server->isOnline()) {
            throw ApiException::conflict('The volume\'s server is offline, so its files cannot be reached.');
        }
        if ($volume->type === 'nfs') {
            throw ApiException::conflict('NFS volumes are not supported by the file manager yet.');
        }
        $payload = ['volume' => $volume->name, 'path' => (string) ($payload['path'] ?? '')] + $payload;
        $job = $this->jobs->create('files', ['execute'], $volume, ['operation' => $operation, 'path' => $payload['path']]);
        $this->bus->dispatch($server, self::OPERATIONS[$operation], $payload, $job);
        if (in_array($operation, self::WRITES, true)) {
            $this->audit->log('files.'.$operation, 'volumes', $volume->id, array_filter([
                'path' => $payload['path'], 'new_name' => $payload['new_name'] ?? null,
                'recursive' => $payload['recursive'] ?? null, 'bytes' => isset($payload['content']) ? strlen($payload['content']) : null,
                'job_id' => $job->id,
            ], fn ($v) => $v !== null));
        }

        return $job;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        if ($command->status !== 'succeeded') {
            $this->jobs->fail($job, $this->friendly((string) $command->message), 'execute');

            return;
        }
        $this->jobs->succeed($job, (array) $command->output);
    }

    /** Agent errors carry the host path; show the part the user knows. */
    private function friendly(string $message): string
    {
        $message = preg_replace('#^(open|stat|lstat|mkdir|remove|rename|openat|statat) \S+: #', '', $message) ?? $message;

        return match (true) {
            str_contains($message, 'path escapes from parent') => 'That path is outside the volume.',
            str_contains($message, 'no such file or directory') => 'That file or folder no longer exists.',
            str_contains($message, 'file exists') => 'Something with that name already exists.',
            str_contains($message, 'permission denied') => 'Permission denied.',
            default => $message ?: 'The file operation failed on the server.',
        };
    }
}
