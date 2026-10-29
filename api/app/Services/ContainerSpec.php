<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\Registry;
use App\Models\Secret;

/**
 * Builds a container.create payload in the agent's exact shape and applies
 * CTR-007 secure defaults (the agent enforces cap_drop ALL, no-new-privileges,
 * non-privileged, no host network itself; we never request otherwise).
 */
class ContainerSpec
{
    public const SAFE_CAPS = ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'FSETID', 'KILL', 'SETGID', 'SETUID', 'NET_BIND_SERVICE'];

    public static function build(array $input, ?Organization $org, array $labels = []): array
    {
        if (! empty($input['privileged'])) {
            throw ApiException::validation(['privileged' => ['Privileged containers are not allowed.']]);
        }
        $user = $input['user'] ?? null;
        if ($user !== null && preg_match('/^(root|0)(:|$)/', (string) $user)) {
            throw ApiException::validation(['user' => ['Containers may not run as root. Omit the user to use the image default or pick a non-root UID.']]);
        }
        $secure = $org?->setting('security.secure_container_defaults', true) ?? true;

        $env = [];
        foreach ($input['env'] ?? [] as $k => $v) {
            if (is_array($v)) {
                $env[$v['key']] = (string) ($v['value'] ?? '');
            } else {
                $env[(string) $k] = (string) $v;
            }
        }
        // Secrets are referenced by id and resolved only into the agent payload.
        foreach ($input['secrets'] ?? [] as $ref) {
            $secret = Secret::find($ref['secret_id'] ?? null);
            if (! $secret) {
                throw ApiException::validation(['secrets' => ['Unknown secret reference.']]);
            }
            $env[$ref['env'] ?? strtoupper($secret->name)] = $secret->value;
        }

        $spec = [
            'name' => $input['name'] ?? null,
            'image' => $input['image'],
            'env' => (object) $env,
            'restart_policy' => $input['restart_policy'] ?? 'unless-stopped',
            'read_only_rootfs' => (bool) ($input['read_only_rootfs'] ?? ($input['read_only'] ?? $secure)),
            'pids_limit' => (int) ($input['pids_limit'] ?? 1024),
            'labels' => (object) array_merge(['io.dockermgr.managed' => 'true'], $input['labels'] ?? [], $labels),
        ];
        foreach (['cmd', 'entrypoint', 'working_dir', 'max_retries', 'pull', 'start'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null) {
                $spec[$field] = $input[$field];
            }
        }
        if ($user) {
            $spec['user'] = (string) $user;
        }
        if (! empty($input['ports'])) {
            $spec['ports'] = array_map(fn ($p) => array_filter([
                'container_port' => (int) $p['container_port'],
                'host_port' => isset($p['host_port']) ? (int) $p['host_port'] : null,
                'protocol' => $p['protocol'] ?? 'tcp',
                'host_ip' => $p['host_ip'] ?? null,
            ], fn ($v) => $v !== null), $input['ports']);
        }
        if (! empty($input['network'])) {
            if ($input['network'] === 'host') {
                throw ApiException::validation(['network' => ['Host networking is not allowed for containers.']]);
            }
            $spec['networks'] = [['name' => $input['network']]];
        }
        if (! empty($input['networks'])) {
            $spec['networks'] = $input['networks'];
        }
        if (! empty($input['mounts'])) {
            $spec['mounts'] = array_map(fn ($m) => ['type' => $m['type'] ?? 'volume', 'source' => $m['source'], 'target' => $m['target'], 'read_only' => (bool) ($m['read_only'] ?? false)], $input['mounts']);
        }
        if ($spec['read_only_rootfs']) {
            $spec['tmpfs'] = $input['tmpfs'] ?? ['/tmp'];
        }
        // Unset limits mean unlimited, so one tenant's container can starve the
        // host. Fall back to the resource envelope the customer is sold.
        $limits = $input['limits'] ?? [];
        $plan = self::planFor($org);
        if ($plan) {
            $limits += array_filter([
                'cpus' => $plan->vcpu ?: null,
                'memory_mb' => $plan->ram_mb ?: null,
            ]);
        }
        if (isset($limits['memory_mb']) || isset($input['memory_mb'])) {
            $spec['memory_mb'] = (int) ($limits['memory_mb'] ?? $input['memory_mb']);
        }
        if (isset($limits['cpus']) || isset($input['cpus'])) {
            $spec['cpus'] = (float) ($limits['cpus'] ?? $input['cpus']);
        }
        if (! empty($input['cap_add'])) {
            $bad = array_diff($input['cap_add'], self::SAFE_CAPS);
            if ($bad) {
                throw ApiException::validation(['cap_add' => ['Capability not allowed: '.implode(', ', $bad)]]);
            }
            $spec['cap_add'] = array_values($input['cap_add']);
        }
        if (! empty($input['healthcheck'])) {
            $h = $input['healthcheck'];
            $spec['healthcheck'] = array_filter([
                'test' => $h['test'] ?? null, 'interval_s' => $h['interval_s'] ?? 30, 'timeout_s' => $h['timeout_s'] ?? 5,
                'retries' => $h['retries'] ?? 3, 'start_period_s' => $h['start_period_s'] ?? 10,
            ], fn ($v) => $v !== null);
        }
        if (! empty($input['registry_id'])) {
            $registry = Registry::findOrFail($input['registry_id']);
            $spec['registry_auth'] = self::registryAuth($registry->url, $registry->username, $registry->credentials);
        } elseif (! empty($input['registry_auth']['username'])) {
            $spec['registry_auth'] = self::registryAuth($input['registry_auth']['server'] ?? $input['registry_auth']['serveraddress'] ?? '', $input['registry_auth']['username'], $input['registry_auth']['password'] ?? '');
        }

        return array_filter($spec, fn ($v) => $v !== null);
    }

    /** The package the organisation is subscribed to, if any. */
    private static function planFor(?Organization $org): ?\App\Models\Plan
    {
        if (! $org) {
            return null;
        }
        // Platform-admin context spans tenants, so the scope must not apply here.
        $planId = \App\Models\Subscription::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->value('plan_id');

        return $planId ? \App\Models\Plan::find($planId) : null;
    }

    public static function registryAuth(string $server, ?string $username, ?string $password): array
    {
        return ['username' => (string) $username, 'password' => (string) $password, 'serveraddress' => $server];
    }

    /** Spec as stored on the container row: secrets and credentials removed. */
    public static function redact(array $spec): array
    {
        unset($spec['registry_auth']);
        $spec['env'] = array_keys((array) ($spec['env'] ?? []));
        $spec['security'] = ['cap_drop' => ['ALL'], 'no_new_privileges' => true, 'privileged' => false];

        return $spec;
    }
}
