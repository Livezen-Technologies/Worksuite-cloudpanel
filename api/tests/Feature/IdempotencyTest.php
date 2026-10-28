<?php

namespace Tests\Feature;

use App\Models\Server;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    public function test_same_key_replays_first_response(): void
    {
        [, , $token] = $this->org();
        $first = $this->api($token)->withHeader('Idempotency-Key', 'abc-123')->postJson('/api/v1/servers', ['name' => 'one'])->assertCreated();
        $second = $this->api($token)->withHeader('Idempotency-Key', 'abc-123')->postJson('/api/v1/servers', ['name' => 'one'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Server::allTenants()->count());

        $this->api($token)->withHeader('Idempotency-Key', 'abc-123')->postJson('/api/v1/servers', ['name' => 'different'])->assertStatus(409)->assertJsonPath('error.code', 'conflict');
        $this->api($token)->withHeader('Idempotency-Key', 'other')->postJson('/api/v1/servers', ['name' => 'two'])->assertCreated();
        $this->assertSame(2, Server::allTenants()->count());
    }

    public function test_keys_are_per_user(): void
    {
        [, $org, $tokenA] = $this->org();
        [, $tokenB] = $this->member($org, 'owner');
        $this->api($tokenA)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/servers', ['name' => 'a'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->api($tokenB)->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/servers', ['name' => 'a'])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(2, Server::allTenants()->count());
    }
}
