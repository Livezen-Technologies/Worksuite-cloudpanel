<?php

namespace App\Support;

use App\Models\BackupPolicy;
use App\Models\Secret;
use Aws\S3\S3Client;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * Restic repository location, environment and keys for a backup scope (BAK-003/004).
 *
 * A scope is a policy (its own repository + password) or, for customer app backups without a
 * policy, the organisation. The repository password is a random secret stored encrypted with
 * APP_KEY in `secrets`; a recovery copy is stored separately, encrypted with BACKUP_RECOVERY_KEY.
 */
class BackupDestination
{
    public const S3_LIKE = ['s3', 'r2', 'b2'];

    public function __construct(public readonly ?BackupPolicy $policy, public readonly string $organizationId) {}

    public function type(): string
    {
        return $this->policy?->destination ?? 'local';
    }

    public function config(): array
    {
        return $this->policy?->destination_config ?? [];
    }

    private function scope(): string
    {
        return $this->policy ? 'policy-'.$this->policy->id : 'org';
    }

    /** Key prefix inside the bucket (or local root) for this scope's repository. */
    public function prefix(): string
    {
        $base = trim((string) ($this->config()['prefix'] ?? 'dockermgr'), '/');

        return ltrim($base.'/'.$this->organizationId.'/'.$this->scope(), '/');
    }

    public function repository(): string
    {
        $c = $this->config();

        return match ($this->type()) {
            's3', 'r2', 'b2' => 's3:'.rtrim($this->endpoint(), '/').'/'.($c['bucket'] ?? 'backups').'/'.$this->prefix(),
            'gdrive' => 'rclone:gdrive:'.$this->prefix(),
            // BKM-007: SFTP through rclone, configured entirely by environment (see env()).
            'sftp' => 'rclone:dmpsftp:'.rtrim($c['path'] ?? 'backups', '/').'/'.$this->prefix(),
            default => rtrim($c['path'] ?? config('backups.local_root'), '/').'/'.$this->organizationId.'/'.$this->scope(),
        };
    }

    public function endpoint(): string
    {
        $c = $this->config();
        if (! empty($c['endpoint'])) {
            return $c['endpoint'];
        }

        return match ($this->type()) {
            'r2' => 'https://'.($c['account_id'] ?? 'account').'.r2.cloudflarestorage.com',
            'b2' => 'https://s3.'.($c['region'] ?? 'us-west-004').'.backblazeb2.com',
            default => 'https://s3.'.($c['region'] ?? 'us-east-1').'.amazonaws.com',
        };
    }

    public function region(): string
    {
        return $this->config()['region'] ?? ($this->type() === 'r2' ? 'auto' : 'us-east-1');
    }

    /** restic environment: password + S3-compatible credentials. */
    public function env(): array
    {
        $env = ['RESTIC_PASSWORD' => $this->password()];
        if (in_array($this->type(), self::S3_LIKE, true)) {
            $c = $this->config();
            $env += [
                'AWS_ACCESS_KEY_ID' => (string) ($c['access_key'] ?? ''),
                'AWS_SECRET_ACCESS_KEY' => (string) ($c['secret_key'] ?? ''),
                'AWS_DEFAULT_REGION' => $this->region(),
            ];
        }

        if ($this->type() === 'sftp') {
            $c = $this->config();
            $env += array_filter([
                'RCLONE_CONFIG_DMPSFTP_TYPE' => 'sftp',
                'RCLONE_CONFIG_DMPSFTP_HOST' => (string) ($c['host'] ?? ''),
                'RCLONE_CONFIG_DMPSFTP_PORT' => (string) ($c['port'] ?? 22),
                'RCLONE_CONFIG_DMPSFTP_USER' => (string) ($c['user'] ?? ''),
                // rclone reads "\n" in key_pem as a line break; env values cannot hold real ones.
                'RCLONE_CONFIG_DMPSFTP_KEY_PEM' => str_replace(["\r\n", "\n"], '\n', trim((string) ($c['private_key'] ?? ''))),
                'RCLONE_CONFIG_DMPSFTP_KNOWN_HOSTS_FILE' => (string) ($c['known_hosts_file'] ?? ''),
            ], fn ($v) => $v !== '');
        }

        return $env;
    }

    /** The repository password, created on first use. */
    public function password(): string
    {
        [$type, $id] = $this->policy ? ['BackupPolicy', $this->policy->id] : ['Organization', $this->organizationId];
        $secret = Secret::allTenants()->where('organization_id', $this->organizationId)
            ->where('owner_type', $type)->where('owner_id', $id)->where('name', 'restic-repository-password')->first();
        if ($secret) {
            return $secret->value;
        }
        // Policies created before key separation used recovery_key as the password.
        $password = ($this->policy && $this->policy->recovery_key && ! str_starts_with($this->policy->recovery_key, 'escrow:'))
            ? $this->policy->recovery_key : Str::password(48, symbols: false);
        Secret::allTenants()->create(['organization_id' => $this->organizationId, 'name' => 'restic-repository-password', 'value' => $password, 'owner_type' => $type, 'owner_id' => $id]);
        $this->storeRecovery($password);

        return $password;
    }

    /** BAK-004: recovery copy, encrypted with a key that is not APP_KEY when configured. */
    private function storeRecovery(string $password): void
    {
        $escrow = self::escrow($password);
        if ($this->policy) {
            $this->policy->forceFill(['recovery_key' => $escrow])->saveQuietly();
        } else {
            Secret::allTenants()->create(['organization_id' => $this->organizationId, 'name' => 'restic-recovery-escrow', 'value' => $escrow, 'owner_type' => 'Organization', 'owner_id' => $this->organizationId]);
        }
    }

    public static function escrow(string $password): string
    {
        $key = config('backups.recovery_key');

        return $key ? 'escrow:'.(new Encrypter(base64_decode(Str::after($key, 'base64:')), 'aes-256-cbc'))->encryptString($password) : 'escrow:app-key:'.encrypt($password);
    }

    public static function unescrow(string $escrow, ?string $recoveryKey = null): string
    {
        $payload = Str::after($escrow, 'escrow:');
        if (str_starts_with($payload, 'app-key:')) {
            return decrypt(Str::after($payload, 'app-key:'));
        }
        $key = $recoveryKey ?? config('backups.recovery_key');

        return (new Encrypter(base64_decode(Str::after($key, 'base64:')), 'aes-256-cbc'))->decryptString($payload);
    }

    public function isS3(): bool
    {
        return in_array($this->type(), self::S3_LIKE, true);
    }

    public function s3(): S3Client
    {
        $c = $this->config();

        return new S3Client([
            'version' => 'latest', 'region' => $this->region(), 'endpoint' => $this->endpoint(),
            'use_path_style_endpoint' => (bool) ($c['path_style'] ?? true),
            'credentials' => ['key' => (string) ($c['access_key'] ?? ''), 'secret' => (string) ($c['secret_key'] ?? '')],
        ]);
    }

    public function bucket(): string
    {
        return $this->config()['bucket'] ?? 'backups';
    }

    /** Presigned PUT for the agent to upload an export. */
    public function presignPut(string $key, \DateTimeInterface $expires): string
    {
        $s3 = $this->s3();
        $cmd = $s3->getCommand('PutObject', ['Bucket' => $this->bucket(), 'Key' => $key, 'ContentType' => 'application/gzip']);

        return (string) $s3->createPresignedRequest($cmd, $expires)->getUri();
    }

    public function presignGet(string $key, \DateTimeInterface $expires, string $filename): string
    {
        $s3 = $this->s3();
        $cmd = $s3->getCommand('GetObject', ['Bucket' => $this->bucket(), 'Key' => $key, 'ResponseContentDisposition' => 'attachment; filename="'.$filename.'"']);

        return (string) $s3->createPresignedRequest($cmd, $expires)->getUri();
    }

    /** Non-secret view of a destination config for the API (keys are write-only). */
    public static function present(?array $config): array
    {
        $config ??= [];
        $out = array_intersect_key($config, array_flip(['endpoint', 'bucket', 'region', 'prefix', 'path', 'account_id', 'path_style', 'host', 'port', 'user']));
        $out['has_access_key'] = ! empty($config['access_key']);
        $out['has_secret_key'] = ! empty($config['secret_key']);
        $out['has_private_key'] = ! empty($config['private_key']);

        return $out;
    }
}
