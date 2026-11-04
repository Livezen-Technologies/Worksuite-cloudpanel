<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\FileShare;
use App\Models\Job;
use App\Models\Volume;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FM File Sharing. A link shares a snapshot: the selection is downloaded from the
 * agent once, when the link is made, and kept on the panel until the link ends. Later
 * edits to the files do not change what the link serves. The token is shown once and
 * stored only as a hash.
 */
class FileShareService
{
    public const MAX_HOURS = 720;   // 30 days

    public function __construct(private FileService $files, private AuditLogger $audit) {}

    /** @return array{0: FileShare, 1: string} the share and its token */
    public function create(Volume $volume, array $paths, int $hours, ?int $maxDownloads, ?string $password): array
    {
        $job = $this->files->download($volume, $paths, share: true);
        $token = Str::random(40);
        $share = FileShare::create([
            'organization_id' => $job->organization_id, 'volume_id' => $volume->id, 'job_id' => $job->id, 'paths' => $paths,
            'status' => 'preparing', 'token_hash' => hash('sha256', $token), 'password_hash' => $password !== null && $password !== '' ? Hash::make($password) : null,
            'expires_at' => now()->addHours($hours), 'max_downloads' => $maxDownloads, 'created_by' => auth()->id(),
        ]);
        $this->audit->log('files.share_created', 'volumes', $volume->id, [
            'paths' => $paths, 'share_id' => $share->id, 'expires_at' => $share->expires_at->toIso8601ZuluString(),
            'max_downloads' => $maxDownloads, 'password' => $share->password_hash !== null,
        ]);

        return [$share, $token];
    }

    /** Called when the snapshot download finishes: keeps the file for the link's lifetime. */
    public function snapshotFinished(Job $job, bool $succeeded): void
    {
        $share = FileShare::allTenants()->where('job_id', $job->id)->first();
        if (! $share) {
            return;
        }
        if (! $succeeded) {
            $share->update(['status' => 'failed']);

            return;
        }
        $disk = Storage::disk('local');
        $disk->move(FileService::downloadPath($job->id), $share->storagePath());
        $disk->deleteDirectory(FileService::TRANSFER_DIR.'/'.$job->id);
        $share->update(['status' => 'ready', 'name' => $job->fresh()->result['name'] ?? 'download', 'format' => $job->fresh()->result['format'] ?? null, 'size' => $disk->size($share->storagePath())]);
    }

    public function revoke(FileShare $share): void
    {
        $share->update(['revoked_at' => now()]);
        Storage::disk('local')->deleteDirectory(dirname($share->storagePath()));
        $this->audit->log('files.share_revoked', 'volumes', $share->volume_id, ['share_id' => $share->id, 'paths' => $share->paths]);
    }

    /** The public side: the share for a token, once the password (if any) is right. Counts the download. */
    public function open(string $token, ?string $password): FileShare
    {
        $share = FileShare::allTenants()->where('token_hash', hash('sha256', $token))->first();
        if (! $share) {
            throw new ApiException('not_found', 'This link does not exist.', 404);
        }
        if ($reason = $share->unavailableReason()) {
            throw new ApiException('gone', match ($reason) {
                'preparing' => 'This link is still being prepared. Try again in a minute.',
                'failed' => 'The files for this link could not be prepared.',
                default => "This link has {$reason}.",
            }, $reason === 'preparing' ? 409 : 410);
        }
        if ($share->password_hash && ($password === null || ! Hash::check($password, $share->password_hash))) {
            throw new ApiException('password_required', $password === null ? 'This link needs a password.' : 'Wrong password.', 401);
        }
        $share->increment('download_count', 1, ['last_downloaded_at' => now()]);

        return $share;
    }

    /** Ended links lose their stored copy; the row stays for the audit trail. */
    public function prune(): int
    {
        $n = 0;
        $disk = Storage::disk('local');
        FileShare::allTenants()->where(fn ($q) => $q->where('expires_at', '<', now())->orWhereNotNull('revoked_at')->orWhereColumn('download_count', '>=', 'max_downloads'))
            ->each(function (FileShare $s) use ($disk, &$n) {
                if ($disk->exists($s->storagePath())) {
                    $disk->deleteDirectory(dirname($s->storagePath()));
                    $n++;
                }
            });

        return $n;
    }

    public static function present(FileShare $s): array
    {
        return [
            'id' => $s->id, 'paths' => $s->paths, 'name' => $s->name, 'format' => $s->format, 'size' => $s->size, 'status' => $s->unavailableReason() ?? 'active',
            'expires_at' => $s->expires_at->toIso8601ZuluString(), 'max_downloads' => $s->max_downloads, 'download_count' => $s->download_count,
            'password' => $s->password_hash !== null, 'last_downloaded_at' => $s->last_downloaded_at?->toIso8601ZuluString(),
            'created_at' => $s->created_at?->toIso8601ZuluString(),
        ];
    }
}
