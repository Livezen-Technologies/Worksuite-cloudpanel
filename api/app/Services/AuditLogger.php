<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Tamper-resistant audit log: each row stores hash = sha256(prev_hash + payload),
 * chained per organisation. verify() recomputes the chain.
 */
class AuditLogger
{
    public function log(string $action, ?string $resourceType = null, ?string $resourceId = null, array $meta = [], ?string $organizationId = null, ?string $userId = null, ?string $actor = null): AuditLog
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $organizationId ??= app(Tenant::class)->id();
        $user = $request?->user();
        $userId ??= $user?->getKey();

        $meta = json_decode(json_encode($meta), true) ?: [];

        return DB::transaction(function () use ($action, $resourceType, $resourceId, $meta, $organizationId, $userId, $actor, $request, $user) {
            $prev = AuditLog::where('organization_id', $organizationId)->lockForUpdate()->orderByDesc('id')->value('hash');
            $row = [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'actor' => $actor ?? $user?->email ?? 'system',
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'ip' => $request?->ip(),
                'request_id' => $request?->attributes->get('request_id'),
                'meta' => $meta ?: null,
                'created_at' => now()->utc()->startOfSecond(),
            ];
            $row['prev_hash'] = $prev;
            $row['hash'] = self::hash($prev, $row);
            $log = AuditLog::create($row);
            if ($organizationId) {
                Organization::whereKey($organizationId)->update(['last_audit_hash' => $row['hash']]);
            }

            return $log;
        });
    }

    public static function hash(?string $prev, array $row): string
    {
        $payload = CanonicalPayload::of([
            'organization_id' => $row['organization_id'],
            'user_id' => $row['user_id'],
            'actor' => $row['actor'],
            'action' => $row['action'],
            'resource_type' => $row['resource_type'],
            'resource_id' => $row['resource_id'],
            'ip' => $row['ip'],
            'meta' => $row['meta'],
            'created_at' => $row['created_at'] instanceof \DateTimeInterface ? $row['created_at']->format('Y-m-d\TH:i:s\Z') : $row['created_at'],
        ]);

        return hash('sha256', ($prev ?? '').$payload);
    }

    /** @return array{valid: bool, checked: int, broken_at: ?int} */
    public function verify(?string $organizationId): array
    {
        $prev = null;
        $checked = 0;
        foreach (AuditLog::where('organization_id', $organizationId)->orderBy('id')->cursor() as $log) {
            $row = $log->getAttributes();
            $row['meta'] = $log->meta;
            $row['created_at'] = $log->created_at->utc();
            if ($log->prev_hash !== $prev || ! hash_equals($log->hash, self::hash($prev, $row))) {
                return ['valid' => false, 'checked' => $checked, 'broken_at' => $log->id];
            }
            $prev = $log->hash;
            $checked++;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
