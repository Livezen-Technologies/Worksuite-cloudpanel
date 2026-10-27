<?php

namespace Tests;

use App\Models\Agent;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\OrganizationService;
use App\Support\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = ReferenceDataSeeder::class;

    /** Creates an organisation whose owner has $role; returns [user, org, token]. */
    protected function org(string $role = 'owner', ?string $name = null): array
    {
        $user = User::create(['name' => 'User '.Str::random(4), 'email' => Str::lower(Str::random(8)).'@example.test', 'password' => 'password']);
        $org = app(OrganizationService::class)->create($name ?? 'Org '.Str::random(4), $user);
        if ($role !== 'owner') {
            Membership::allTenants()->where('user_id', $user->id)->update(['role' => $role]);
        }

        return [$user->fresh(), $org, $this->tokenFor($user->fresh())];
    }

    /** Adds another member to an org. */
    protected function member(Organization $org, string $role): array
    {
        $user = User::create(['name' => 'Member', 'email' => Str::lower(Str::random(8)).'@example.test', 'password' => 'password', 'current_organization_id' => $org->id]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $this->tokenFor($user)];
    }

    protected function tokenFor(User $user, array $abilities = ['*']): string
    {
        $token = $user->createToken('session', $abilities);
        $token->accessToken->forceFill(['organization_id' => $user->current_organization_id])->save();

        return $token->plainTextToken;
    }

    protected function api(string $token): static
    {
        app(Tenant::class)->set(null);

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    protected function server(Organization $org, array $attributes = []): Server
    {
        return Server::allTenants()->create(array_merge([
            'organization_id' => $org->id, 'name' => 'srv-'.Str::lower(Str::random(5)), 'ip' => '198.51.100.'.random_int(2, 250),
            'environment' => 'production', 'region' => 'eu-central', 'os' => 'Ubuntu 24.04', 'kernel' => '6.8', 'docker_version' => '27.0.1',
            'cpu_cores' => 8, 'ram_mb' => 16384, 'disk_gb' => 200, 'cpu_pct' => 10, 'ram_pct' => 20, 'disk_pct' => 30,
            'last_heartbeat_at' => now(),
        ], $attributes));
    }

    /** Enrols an agent for a server through the API; returns [agent_secret, hmac_key]. */
    protected function enrollAgent(Server $server, string $userToken): array
    {
        $enrollment = $this->api($userToken)->postJson('/api/v1/servers/enrollment', ['server_id' => $server->id])->assertCreated()->json('data');
        $response = $this->withHeaders(['Authorization' => ''])->postJson('/agent/v1/enroll', [
            'token' => $enrollment['token'], 'hostname' => 'node.test',
            'facts' => ['os' => 'Ubuntu 24.04', 'kernel' => '6.8.0', 'docker_version' => '27.1.0', 'cpu_cores' => 8, 'ram_mb' => 16384, 'disk_gb' => 200, 'arch' => 'amd64'],
        ])->assertOk()->json();

        return [$response['agent_secret'], $response['hmac_key']];
    }

    protected function heartbeat(string $secret, array $body = []): array
    {
        app(Tenant::class)->set(null);

        return $this->withHeaders(['Authorization' => 'Bearer '.$secret, 'Accept' => 'application/json'])->postJson('/agent/v1/heartbeat', array_merge([
            'agent_version' => '1.0.0', 'stats' => ['cpu_pct' => 12.5, 'ram_pct' => 40, 'disk_pct' => 50, 'net_rx' => 100, 'net_tx' => 50],
            'containers' => [], 'images' => [], 'networks' => [], 'volumes' => [],
        ], $body))->assertOk()->json();
    }

    protected function commandResult(string $secret, string $id, string $status = 'succeeded', array $extra = [])
    {
        app(Tenant::class)->set(null);

        return $this->withHeaders(['Authorization' => 'Bearer '.$secret, 'Accept' => 'application/json'])
            ->postJson("/agent/v1/commands/{$id}/result", array_merge(['status' => $status], $extra));
    }
}
