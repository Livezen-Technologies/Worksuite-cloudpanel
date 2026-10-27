<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\CrudController;
use App\Models\BackupJob;
use App\Models\BackupPolicy;
use App\Support\Tenant;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
            'destination' => 'sometimes|in:local,s3,b2,r2,gdrive',
            'destination_config' => 'nullable|array',
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
        if (! $model) {
            $data['recovery_key'] = Str::random(48);
        }

        return $data;
    }

    protected function present(Model $policy): array
    {
        $recent = BackupJob::where('backup_policy_id', $policy->id)->where('created_at', '>=', now()->subDays(14))->get();

        return $policy->toArray() + [
            'next_run_at' => $policy->enabled ? (new CronExpression($policy->schedule))->getNextRunDate(now())->format(DATE_ATOM) : null,
            'success_14d' => collect(range(13, 0))->map(fn ($d) => [
                'date' => now()->subDays($d)->toDateString(),
                'succeeded' => $recent->filter(fn ($j) => $j->created_at->isSameDay(now()->subDays($d)) && $j->status === 'succeeded')->count(),
                'failed' => $recent->filter(fn ($j) => $j->created_at->isSameDay(now()->subDays($d)) && $j->status === 'failed')->count(),
            ])->values(),
        ];
    }
}
