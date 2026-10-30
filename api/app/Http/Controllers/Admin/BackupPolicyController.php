<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\CrudController;
use App\Models\BackupJob;
use App\Models\BackupPolicy;
use App\Support\Tenant;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use App\Support\BackupDestination;

class BackupPolicyController extends CrudController
{
    protected string $model = BackupPolicy::class;

    protected array $filters = ['target_type', 'destination', 'enabled'];

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:120",
            'target_type' => "{$req}|in:application,volume,database,server",
            'target_id' => "{$req}|uuid",
            'method' => 'sometimes|in:restic,dump',
            'schedule' => ['sometimes', 'string', function ($attr, $value, $fail) {
                CronExpression::isValidExpression($value) || $fail('Use a valid cron expression, e.g. "0 3 * * *".');
            }],
            'destination' => 'sometimes|in:local,s3,b2,r2,gdrive,sftp',
            'destination_config' => 'nullable|array',
            'destination_config.endpoint' => 'nullable|url|max:255',
            'destination_config.bucket' => 'nullable|string|max:63|regex:/^[a-z0-9][a-z0-9.\-]{1,62}$/',
            'destination_config.region' => 'nullable|string|max:40',
            'destination_config.prefix' => 'nullable|string|max:120|regex:/^[A-Za-z0-9._\/-]*$/',
            'destination_config.path' => 'nullable|string|max:255|regex:/^\/[A-Za-z0-9._\/-]+$/',
            'destination_config.access_key' => 'nullable|string|max:255',
            'destination_config.secret_key' => 'nullable|string|max:255',
            'destination_config.account_id' => 'nullable|string|max:64',
            // BKM-007: SFTP (key authentication only; the key is write-only).
            'destination_config.host' => ['nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.-]+$/'],
            'destination_config.port' => 'nullable|integer|min:1|max:65535',
            'destination_config.user' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'destination_config.private_key' => ['nullable', 'string', 'max:16000', 'regex:/^-----BEGIN [A-Z ]*PRIVATE KEY-----/'],
            // BKM-003 / BKM-007 / BKM-008
            'keep_daily' => 'sometimes|nullable|integer|min:1|max:365',
            'keep_weekly' => 'sometimes|nullable|integer|min:1|max:520',
            'keep_monthly' => 'sometimes|nullable|integer|min:1|max:240',
            'capacity_gb' => 'sometimes|nullable|integer|min:1|max:1000000',
            'object_lock_days' => 'sometimes|nullable|integer|min:1|max:3650',
            'check_subset' => ['sometimes', 'string', 'regex:/^((100|[0-9]{1,2}(\.[0-9]+)?)%|[0-9]+\/[0-9]+|[0-9]+[KMG])$/'],
            'restore_test_schedule' => ['sometimes', 'nullable', 'string', function ($attr, $value, $fail) {
                $value === null || CronExpression::isValidExpression($value) || $fail('Use a valid cron expression, e.g. "0 4 * * 0".');
            }],
            'retention_days' => 'sometimes|integer|min:1|max:3650',
            'encrypted' => 'sometimes|boolean',
            'enabled' => 'sometimes|boolean',
        ];
    }

    protected function prepare(array $data, ?Model $model = null): array
    {
        $remote = ($data['destination'] ?? $model?->destination ?? 'local') !== 'local';
        // BAK-004: remote backups are always encrypted when the policy requires it.
        if ($remote && ($data['encrypted'] ?? true) === false && Tenant::current()->setting('security.mandatory_backup_encryption')) {
            throw ApiException::validation(['encrypted' => ['Remote backups must be encrypted.']]);
        }
        // Credentials are write-only: an update without them keeps the stored ones.
        if (array_key_exists('destination_config', $data)) {
            $previous = $model?->destination_config ?? [];
            $next = array_filter($data['destination_config'] ?? [], fn ($v) => $v !== null && $v !== '');
            foreach (['access_key', 'secret_key', 'private_key'] as $key) {
                if (! isset($next[$key]) && isset($previous[$key])) {
                    $next[$key] = $previous[$key];
                }
            }
            $data['destination_config'] = $next;
        }
        $destination = $data['destination'] ?? $model?->destination ?? 'local';
        if (in_array($destination, BackupDestination::S3_LIKE, true)) {
            $config = $data['destination_config'] ?? $model?->destination_config ?? [];
            $missing = array_values(array_filter(['bucket', 'access_key', 'secret_key'], fn ($k) => empty($config[$k])));
            if ($missing) {
                throw ApiException::validation(collect($missing)->mapWithKeys(fn ($k) => ["destination_config.{$k}" => ['Required for '.strtoupper($destination).' destinations.']])->all());
            }
        }

        if ($destination === 'sftp') {
            $config = $data['destination_config'] ?? $model?->destination_config ?? [];
            $missing = array_values(array_filter(['host', 'user', 'private_key', 'path'], fn ($k) => empty($config[$k])));
            if ($missing) {
                throw ApiException::validation(collect($missing)->mapWithKeys(fn ($k) => ["destination_config.{$k}" => ['Required for SFTP destinations.']])->all());
            }
        }
        // BKM-008: object lock needs an S3 API, and locked data must outlive the retention window.
        $lock = array_key_exists('object_lock_days', $data) ? $data['object_lock_days'] : $model?->object_lock_days;
        if ($lock) {
            if (! in_array($destination, BackupDestination::S3_LIKE, true)) {
                throw ApiException::validation(['object_lock_days' => ['Object lock needs an S3, R2 or B2 bucket with object lock turned on.']]);
            }
            $retention = $data['retention_days'] ?? $model?->retention_days ?? 14;
            if ($lock > $retention) {
                throw ApiException::validation(['object_lock_days' => ["Lock for at most the retention window ({$retention} days), or snapshots cannot be forgotten when they expire."]]);
            }
        }

        return $data;
    }

    protected function present(Model $policy): array
    {
        $recent = BackupJob::where('backup_policy_id', $policy->id)->where('created_at', '>=', now()->subDays(14))->get();

        return $policy->toArray() + [
            'destination_summary' => BackupDestination::present($policy->destination_config),
            'usage' => \App\Services\BackupService::usage($policy),
            'next_run_at' => $policy->enabled ? (new CronExpression($policy->schedule))->getNextRunDate(now())->format(DATE_ATOM) : null,
            'success_14d' => collect(range(13, 0))->map(fn ($d) => [
                'date' => now()->subDays($d)->toDateString(),
                'succeeded' => $recent->filter(fn ($j) => $j->created_at->isSameDay(now()->subDays($d)) && $j->status === 'succeeded')->count(),
                'failed' => $recent->filter(fn ($j) => $j->created_at->isSameDay(now()->subDays($d)) && $j->status === 'failed')->count(),
            ])->values(),
        ];
    }
}
