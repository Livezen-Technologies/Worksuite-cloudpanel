<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Services\NotificationService;
use App\Support\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Webhooks are signed; a receiver error is a failed delivery, recorded on the channel. */
class NotificationChannelHealthTest extends TestCase
{
    public function test_webhooks_are_signed_and_a_receiver_error_marks_the_channel_unhealthy(): void
    {
        [, $org, $token] = $this->org();
        Http::fake(['hooks.example.com/*' => Http::response('ok'), 'hooks.slack.com/*' => Http::response('no_service', 404)]);
        [$hook, $slack] = app(Tenant::class)->as($org->id, fn () => [
            NotificationChannel::create(['name' => 'Ops hook', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/dmp']]),
            NotificationChannel::create(['name' => 'Slack', 'type' => 'slack', 'config' => ['url' => 'https://hooks.slack.com/services/T0/B0/secretpath']]),
        ]);

        $secret = $this->api($token)->getJson("/api/v1/notification-channels/{$hook->id}")->assertOk()->json('data.signing_secret');
        $this->api($token)->postJson("/api/v1/notification-channels/{$hook->id}/test")->assertOk();
        Http::assertSent(function (Request $r) use ($secret) {
            if (! str_contains($r->url(), 'hooks.example.com')) {
                return false;
            }
            $expected = 'sha256='.hash_hmac('sha256', $r->header('X-DMP-Timestamp')[0].'.'.$r->body(), $secret);

            return hash_equals($expected, $r->header('X-DMP-Signature')[0]) && json_decode($r->body(), true)['title'] === 'Test notification';
        });
        $this->assertNotNull(NotificationChannel::allTenants()->find($hook->id)->last_delivered_at);

        // A 404 used to count as delivered; now it fails, is recorded and the test says why (without the secret URL).
        $this->api($token)->postJson("/api/v1/notification-channels/{$slack->id}/test")->assertStatus(409)
            ->assertJsonPath('error.message', 'The test was not delivered: HTTP 404 from the receiver');
        $ch = NotificationChannel::allTenants()->find($slack->id);
        $this->assertSame([1, 'HTTP 404 from the receiver'], [$ch->consecutive_failures, $ch->last_error]);
        $this->assertFalse($this->api($token)->getJson("/api/v1/notification-channels/{$slack->id}")->json('data.healthy'));
        $this->assertNull($this->api($token)->getJson("/api/v1/notification-channels/{$slack->id}")->json('data.signing_secret'), 'only webhooks are signed');
        $this->assertStringNotContainsString('secretpath', json_encode($this->api($token)->getJson("/api/v1/notification-channels/{$slack->id}")->json('data')));
    }

    public function test_a_typed_in_webhook_secret_signs_and_stays_write_only(): void
    {
        [, $org, $token] = $this->org();
        Http::fake(['hooks.example.com/*' => Http::response('ok')]);
        $id = $this->api($token)->postJson('/api/v1/notification-channels', ['name' => 'Hook', 'type' => 'webhook', 'target' => 'https://hooks.example.com/x', 'secret' => 'my-shared-secret'])->assertCreated()->json('data.id');
        $this->assertNull($this->api($token)->getJson("/api/v1/notification-channels/{$id}")->json('data.signing_secret'));
        $this->api($token)->postJson("/api/v1/notification-channels/{$id}/test")->assertOk();
        Http::assertSent(fn (Request $r) => hash_equals('sha256='.hash_hmac('sha256', $r->header('X-DMP-Timestamp')[0].'.'.$r->body(), 'my-shared-secret'), $r->header('X-DMP-Signature')[0]));
    }
}
