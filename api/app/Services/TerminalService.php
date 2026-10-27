<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Container;
use App\Models\TerminalSession;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * CTR-008 / LOG-002 terminal sessions behind the WebSocket relay (agent/cmd/relay).
 * The relay authenticates browsers with the session token, gets a signed terminal.open
 * command for the agent (exec of a fixed shell, non-root, in the authorised container),
 * polls for "end session", reports the close and uploads the asciicast recording.
 */
class TerminalService
{
    public function __construct(private CommandBus $bus, private AuditLogger $audit, private Tenant $tenant) {}

    public static function shell(): string
    {
        return config('security.terminal.shells')[0] ?? '/bin/sh';
    }

    /** Relay: validate the browser's token and return the session + signed agent command. */
    public function connect(string $id, string $token, int $cols, int $rows, ?string $ip): array
    {
        return DB::transaction(function () use ($id, $token, $cols, $rows, $ip) {
            $session = TerminalSession::allTenants()->lockForUpdate()->find($id);
            if (! $session || ! hash_equals($session->token_hash, hash('sha256', $token))) {
                throw new ApiException('unauthenticated', 'The terminal token is invalid or expired.', 401);
            }
            if ($session->ended_at || $session->expires_at->isPast()) {
                throw new ApiException('gone', 'This terminal session has ended. Open a new one.', 410);
            }
            if ($session->connected_at) {
                throw ApiException::conflict('This terminal session is already connected; tokens are single use.');
            }
            $container = Container::allTenants()->with(['server' => fn ($q) => $q->withoutGlobalScope('organization')])->find($session->container_id);
            if (! $container || ! $container->docker_id || ! $container->server) {
                throw ApiException::conflict('The container is not running on an agent yet.');
            }
            $server = $container->server;
            $agent = Agent::allTenants()->where('server_id', $server->id)->first();
            if (! $agent) {
                throw ApiException::conflict('The server has no enrolled agent.');
            }
            $cols = max(10, min(1000, $cols ?: 80));
            $rows = max(5, min(1000, $rows ?: 24));
            $payload = [
                'session_id' => $session->id,
                'container_id' => $container->docker_id,
                'shell_user' => $session->shell_user,
                'shell' => $session->shell ?: self::shell(),
                'expires_at' => $session->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'relay_url' => config('security.relay.agent_url'),
                'cols' => $cols,
                'rows' => $rows,
            ];
            $command = $this->tenant->as($session->organization_id, fn () => $this->bus->dispatch($server, 'terminal.open', $payload));
            // Signed now so the relay can push it over the agent's control connection; it stays queued
            // so the next heartbeat delivers it too (the agent's journal drops the duplicate).
            $command->forceFill(['issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')])->save();
            $session->update(['connected_at' => now(), 'command_id' => $command->id]);
            $user = User::find($session->user_id);
            $this->audit->log('terminal.attached', 'containers', $container->id, ['session_id' => $session->id, 'server_id' => $server->id, 'ip' => $ip], $session->organization_id, $session->user_id, $user?->email);

            return [
                'session_id' => $session->id,
                'server_id' => $server->id,
                'container_id' => $container->docker_id,
                'container_name' => $container->name,
                'user_id' => $session->user_id,
                'user_email' => $user?->email,
                'shell_user' => $session->shell_user,
                'shell' => $payload['shell'],
                'expires_at' => $session->expires_at->toIso8601ZuluString(),
                'recording' => (bool) config('security.terminal.record', true),
                'command' => $this->bus->wire($command, $agent->hmac_key),
            ];
        });
    }

    public function status(string $id): array
    {
        $session = TerminalSession::allTenants()->findOrFail($id);

        return [
            'ended' => $session->ended_at !== null || $session->expires_at->isPast(),
            'ended_at' => $session->ended_at?->toIso8601ZuluString(),
            'expires_at' => $session->expires_at->toIso8601ZuluString(),
        ];
    }

    /** Relay reports the close (expired, ended, shell exited, disconnect). */
    public function closed(string $id, string $reason): TerminalSession
    {
        $session = TerminalSession::allTenants()->findOrFail($id);
        if (! $session->ended_at) {
            $session->update(['ended_at' => now(), 'close_reason' => $reason]);
            $this->audit->log('terminal.closed', 'containers', $session->container_id, ['session_id' => $session->id, 'reason' => $reason], $session->organization_id, $session->user_id, 'relay');
        } elseif (! $session->close_reason) {
            $session->update(['close_reason' => $reason]);
        }

        return $session;
    }

    public function storeRecording(string $id, string $cast): TerminalSession
    {
        $session = TerminalSession::allTenants()->findOrFail($id);
        $max = (int) config('security.relay.recording_max_bytes');
        if (strlen($cast) > $max) {
            throw new ApiException('payload_too_large', 'The recording exceeds TERMINAL_RECORDING_MAX_BYTES.', 413);
        }
        $first = strtok($cast, "\n");
        $header = json_decode((string) $first, true);
        if (! is_array($header) || ($header['version'] ?? null) !== 2) {
            throw ApiException::validation(['recording' => ['Expected an asciicast v2 recording.']]);
        }
        Storage::disk(config('security.relay.recording_disk'))->put($session->recording_path, $cast);
        $session->update(['recording_stored' => true, 'recording_bytes' => strlen($cast)]);

        return $session;
    }

    /** User ends the session ("End session"); the relay notices on its next status poll. */
    public function end(TerminalSession $session, ?User $by = null): TerminalSession
    {
        if (! $session->ended_at) {
            $session->update(['ended_at' => now(), 'close_reason' => 'ended by '.($by?->email ?? 'user')]);
            $this->audit->log('terminal.closed', 'containers', $session->container_id, ['session_id' => $session->id, 'reason' => 'ended by user']);
        }

        return $session;
    }

    public function recording(TerminalSession $session): ?string
    {
        $disk = Storage::disk(config('security.relay.recording_disk'));

        return $session->recording_stored && $disk->exists($session->recording_path) ? $disk->get($session->recording_path) : null;
    }

    public static function present(TerminalSession $s): array
    {
        $container = $s->relationLoaded('container') ? $s->container : null;

        return [
            'id' => $s->id,
            'container_id' => $s->container_id,
            'container_name' => $container?->name,
            'server_id' => $container?->server_id,
            'user_id' => $s->user_id,
            'user_email' => $s->user_email ?? null,
            'shell_user' => $s->shell_user,
            'shell' => $s->shell,
            'status' => $s->ended_at ? 'ended' : ($s->expires_at->isPast() ? 'expired' : ($s->connected_at ? 'active' : 'pending')),
            'close_reason' => $s->close_reason,
            'created_at' => $s->created_at?->toIso8601ZuluString(),
            'connected_at' => $s->connected_at?->toIso8601ZuluString(),
            'expires_at' => $s->expires_at->toIso8601ZuluString(),
            'ended_at' => $s->ended_at?->toIso8601ZuluString(),
            'recording' => $s->recording_stored ? ['bytes' => $s->recording_bytes, 'format' => 'asciicast-v2'] : null,
            'ip' => $s->ip,
        ];
    }
}
