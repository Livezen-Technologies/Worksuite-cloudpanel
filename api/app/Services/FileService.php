<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Job;
use App\Models\Volume;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

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
        'upload' => 'fs.upload', 'download' => 'fs.download',
        'copy' => 'fs.copy', 'move' => 'fs.move', 'compress' => 'fs.compress', 'extract' => 'fs.extract',
        'trash' => 'fs.trash', 'trash_list' => 'fs.trash_list', 'trash_restore' => 'fs.trash_restore', 'trash_purge' => 'fs.trash_purge',
    ];

    /** Where upload and download bytes wait on the panel's own disk, one folder per job. */
    public const TRANSFER_DIR = 'file-transfers';

    /** Operations that change files; each is audited with its path (FM-014). */
    private const WRITES = ['write', 'mkdir', 'rename', 'delete', 'copy', 'move', 'compress', 'extract', 'trash', 'trash_restore', 'trash_purge'];

    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit) {}

    public function run(Volume $volume, string $operation, array $payload): Job
    {
        $this->reachable($volume);
        $server = $volume->server;
        $payload = ['volume' => $volume->name, 'path' => (string) ($payload['path'] ?? '')] + $payload;
        $job = $this->jobs->create('files', ['execute'], $volume, ['operation' => $operation, 'path' => $payload['path']]);
        $this->bus->dispatch($server, self::OPERATIONS[$operation], $payload, $job);
        if (in_array($operation, self::WRITES, true)) {
            $this->audit->log('files.'.$operation, 'volumes', $volume->id, array_filter([
                'path' => $payload['path'] !== '' ? $payload['path'] : null, 'paths' => $payload['paths'] ?? null, 'dest' => $payload['dest'] ?? null,
                'ids' => $payload['ids'] ?? null, 'format' => $payload['format'] ?? null, 'new_name' => $payload['new_name'] ?? null,
                'recursive' => $payload['recursive'] ?? null, 'bytes' => isset($payload['content']) ? strlen($payload['content']) : null,
                'job_id' => $job->id,
            ], fn ($v) => $v !== null));
        }

        return $job;
    }

    /**
     * FM-002: the browser sends the files here; the agent then fetches each one
     * over a signed URL and writes it into the volume.
     *
     * @param  UploadedFile[]  $files
     * @param  string[]  $relativePaths  per file, its path inside an uploaded folder ("" for a plain file)
     */
    public function upload(Volume $volume, string $folder, array $files, array $relativePaths, bool $overwrite): Job
    {
        $this->reachable($volume);
        $job = $this->jobs->create('files', ['execute'], $volume, ['operation' => 'upload', 'path' => $folder]);
        $disk = Storage::disk('local');
        $items = [];
        $bytes = 0;
        foreach (array_values($files) as $n => $file) {
            $disk->putFileAs(self::TRANSFER_DIR.'/'.$job->id, $file, (string) $n);
            $relative = trim((string) ($relativePaths[$n] ?? ''), '/') ?: $file->getClientOriginalName();
            $items[] = [
                'path' => trim($folder.'/'.$relative, '/'),
                'url' => $this->agentUrl('agent.file-transfer-get', ['id' => $job->id, 'n' => $n]),
            ];
            $bytes += $file->getSize();
        }
        $this->bus->dispatch($volume->server, 'fs.upload', ['volume' => $volume->name, 'files' => $items, 'overwrite' => $overwrite], $job);
        $this->audit->log('files.upload', 'volumes', $volume->id, ['paths' => array_column($items, 'path'), 'files' => count($items), 'bytes' => $bytes, 'overwrite' => $overwrite, 'job_id' => $job->id]);

        return $job;
    }

    /** FM-003: the agent sends one file as is, or a ZIP of the selection, to the panel. */
    public function download(Volume $volume, array $paths): Job
    {
        $this->reachable($volume);
        $job = $this->jobs->create('files', ['execute'], $volume, ['operation' => 'download', 'paths' => $paths]);
        $this->bus->dispatch($volume->server, 'fs.download', [
            'volume' => $volume->name, 'paths' => $paths,
            'upload' => ['url' => $this->agentUrl('agent.file-transfer-put', ['id' => $job->id]), 'method' => 'PUT'],
        ], $job);
        $this->audit->log('files.download', 'volumes', $volume->id, ['paths' => $paths, 'job_id' => $job->id]);

        return $job;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        if ($command->type === 'fs.upload') {
            Storage::disk('local')->deleteDirectory(self::TRANSFER_DIR.'/'.$job->id);
        }
        if ($command->status !== 'succeeded') {
            $this->jobs->fail($job, $this->friendly((string) $command->message), 'execute');

            return;
        }
        $result = (array) $command->output;
        if ($command->type === 'fs.download') {
            $expires = now()->addHour();
            $result += [
                'download_url' => URL::temporarySignedRoute('files.transfer', $expires, ['id' => $job->id]),
                'expires_at' => $expires->toIso8601ZuluString(),
            ];
        }
        $this->jobs->succeed($job, $result);
    }

    /** The downloaded file a finished fs.download job left on the panel's disk. */
    public static function downloadPath(string $jobId): string
    {
        return self::TRANSFER_DIR.'/'.$jobId.'/download';
    }

    private function reachable(Volume $volume): void
    {
        if (! $volume->server || ! $volume->server->isOnline()) {
            throw ApiException::conflict('The volume\'s server is offline, so its files cannot be reached.');
        }
        if ($volume->type === 'nfs') {
            throw ApiException::conflict('NFS volumes are not supported by the file manager yet.');
        }
    }

    /** A signed URL the agent can call without a bearer token: the signature is the capability. */
    private function agentUrl(string $route, array $params): string
    {
        return rtrim(config('backups.agent_api_url'), '/').URL::temporarySignedRoute($route, now()->addHours(6), $params, absolute: false);
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
