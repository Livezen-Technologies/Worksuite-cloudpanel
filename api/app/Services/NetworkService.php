<?php

namespace App\Services;

use App\Models\Container;
use App\Models\Network;
use App\Models\Server;

/** NET-001..003: network creation and conflict checks. */
class NetworkService
{
    /** @return array{ok: bool, conflicts: array, warnings: array} */
    public function check(Server $server, array $input): array
    {
        $conflicts = [];
        $warnings = [];
        if (! empty($input['name']) && Network::where('server_id', $server->id)->where('name', $input['name'])->exists()) {
            $conflicts[] = ['type' => 'name', 'message' => "A network named {$input['name']} already exists on {$server->name}."];
        }
        if (! empty($input['subnet'])) {
            if (! self::validCidr($input['subnet'])) {
                $conflicts[] = ['type' => 'subnet', 'message' => "{$input['subnet']} is not a valid IPv4 CIDR."];
            } else {
                foreach (Network::where('server_id', $server->id)->whereNotNull('subnet')->get() as $network) {
                    if (self::validCidr($network->subnet) && self::overlaps($input['subnet'], $network->subnet)) {
                        $conflicts[] = ['type' => 'ip', 'message' => "Subnet {$input['subnet']} overlaps {$network->subnet} ({$network->name})."];
                    }
                }
            }
        }
        foreach ((array) ($input['ports'] ?? []) as $port) {
            $port = (int) (is_array($port) ? ($port['host_port'] ?? 0) : $port);
            if (! $port) {
                continue;
            }
            $used = Container::where('server_id', $server->id)->get()->first(fn ($c) => collect($c->ports ?? [])->contains(fn ($p) => (int) preg_replace('/^(?:[\d.]+:)?(\d+).*$/', '$1', (string) $p) === $port || str_contains((string) $p, ":{$port}->") || str_starts_with((string) $p, "{$port}:")));
            if ($used) {
                $conflicts[] = ['type' => 'port', 'message' => "Host port {$port} is already used by {$used->name}."];
            }
            if ($port < 1024 && ! in_array($port, [80, 443], true)) {
                $warnings[] = "Port {$port} is privileged.";
            }
            $warnings[] = "Host port {$port} will be publicly exposed unless bound to 127.0.0.1.";
        }
        if (($input['driver'] ?? 'bridge') === 'host') {
            $warnings[] = 'Host networking removes network isolation and needs approval.';
        }

        return ['ok' => $conflicts === [], 'conflicts' => $conflicts, 'warnings' => array_values(array_unique($warnings))];
    }

    public static function validCidr(string $cidr): bool
    {
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $m)) {
            return false;
        }

        return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (int) $m[2] <= 32;
    }

    public static function overlaps(string $a, string $b): bool
    {
        [$ipA, $maskA] = explode('/', $a);
        [$ipB, $maskB] = explode('/', $b);
        $mask = min((int) $maskA, (int) $maskB);
        $bits = $mask === 0 ? 0 : (~0 << (32 - $mask)) & 0xFFFFFFFF;

        return (ip2long($ipA) & $bits) === (ip2long($ipB) & $bits);
    }
}
