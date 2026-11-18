<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\BackupStorageServer;
use Illuminate\Support\Facades\Process;

/**
 * Backup storage pool: SFTP servers that hold backups. The panel host checks each one with
 * rclone (reachability, free space) and pins its host keys on the first successful check.
 * Jobs outside the panel ask for the pool member with the most free space.
 */
class BackupPoolService
{
    /** rclone environment for one server; the key and known_hosts are written to private temp files. */
    public function rcloneEnv(BackupStorageServer $s, string $dir): array
    {
        $key = $dir.'/key';
        file_put_contents($key, $s->private_key);
        chmod($key, 0600);
        $env = [
            'RCLONE_CONFIG_POOL_TYPE' => 'sftp', 'RCLONE_CONFIG_POOL_HOST' => $s->host, 'RCLONE_CONFIG_POOL_PORT' => (string) $s->port,
            'RCLONE_CONFIG_POOL_USER' => $s->username, 'RCLONE_CONFIG_POOL_KEY_FILE' => $key,
        ];
        if ($s->host_keys) {
            file_put_contents($dir.'/known_hosts', $s->host_keys);
            $env['RCLONE_CONFIG_POOL_KNOWN_HOSTS_FILE'] = $dir.'/known_hosts';
        }

        return $env;
    }

    /** Connects, records free space and pins host keys. Never throws for an unreachable server. */
    public function check(BackupStorageServer $s): BackupStorageServer
    {
        $dir = sys_get_temp_dir().'/wscp-pool-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            if (! $s->host_keys) {
                $scan = Process::timeout(20)->run(['ssh-keyscan', '-p', (string) $s->port, $s->host]);
                $keys = trim(collect(explode("\n", $scan->output()))->filter(fn ($l) => $l !== '' && ! str_starts_with($l, '#'))->implode("\n"));
                if ($keys === '') {
                    return $this->result($s, 'error', 'Could not reach '.$s->host.' on port '.$s->port.' to read its host key.');
                }
                $s->host_keys = $keys."\n";
            }
            $path = 'pool:'.trim($s->path, '/');
            $about = Process::timeout(60)->env($this->rcloneEnv($s, $dir))->run(['rclone', 'about', $path, '--json']);
            if (! $about->successful()) {
                $s->host_keys = $s->getOriginal('host_keys'); // pin only after a key has worked
                $err = trim(collect(explode("\n", $about->errorOutput()))->filter()->last() ?? '');

                return $this->result($s, 'error', $err !== '' ? mb_substr($err, 0, 480) : 'The server refused the connection.');
            }
            $info = json_decode($about->output(), true) ?: [];
            $s->fill(['total_bytes' => $info['total'] ?? null, 'used_bytes' => $info['used'] ?? null, 'free_bytes' => $info['free'] ?? null]);

            return $this->result($s, 'ok', null);
        } finally {
            Process::run(['rm', '-rf', $dir]);
        }
    }

    private function result(BackupStorageServer $s, string $status, ?string $message): BackupStorageServer
    {
        $s->fill(['status' => $status, 'message' => $message, 'checked_at' => now()])->save();

        return $s;
    }

    /** The enabled, healthy pool member with the most free space. */
    public function pick(?string $organizationId = null): BackupStorageServer
    {
        $q = BackupStorageServer::allTenants()->where('enabled', true)->where('status', 'ok');
        if ($organizationId) {
            $q->where('organization_id', $organizationId);
        }
        $s = $q->orderByDesc('free_bytes')->first();
        if (! $s) {
            throw ApiException::conflict('No backup storage server is enabled and reachable. Add one under Backup manager → Storage pool.');
        }

        return $s;
    }
}
