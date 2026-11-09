<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Domain;
use App\Models\UptimeCheck;
use App\Support\Tenant;
use Tests\TestCase;

/** Monitoring: the public status page publishes component health, never internals. */
class StatusPageTest extends TestCase
{
    public function test_the_status_page_publishes_components_uptime_and_incidents_only(): void
    {
        [, $org, $token] = $this->org();
        $this->getJson('/api/v1/status')->assertNotFound();

        [$api, $web] = app(Tenant::class)->as($org->id, function () {
            $api = Domain::create(['name' => 'api.internal-host.example', 'dns_status' => 'verified']);
            $web = Domain::create(['name' => 'www.example.com', 'dns_status' => 'verified']);
            foreach (range(0, 9) as $i) {
                UptimeCheck::create(['domain_id' => $api->id, 'ok' => $i !== 3, 'status' => 200, 'ms' => 90, 'checked_at' => now()->subMinutes($i * 5)]);
                UptimeCheck::create(['domain_id' => $web->id, 'ok' => true, 'status' => 200, 'ms' => 90, 'checked_at' => now()->subDays(2)->subMinutes($i)]);
            }
            $a = new Alert();
            $a->fill(['resource_type' => 'domain', 'resource_id' => $api->id, 'severity' => 'critical', 'status' => 'open', 'title' => 'api.internal-host.example is down', 'message' => 'Connection refused from 10.0.0.5']);
            $a->save();

            return [$api, $web];
        });

        $settings = $this->api($token)->getJson('/api/v1/status-page')->assertOk()->json('data');
        $this->assertFalse($settings['enabled']);
        $this->assertCount(2, $settings['domains']);
        $this->api($token)->patchJson('/api/v1/status-page', ['components' => [['name' => 'API', 'domain_id' => $api->id], ['name' => 'Dup', 'domain_id' => $api->id]]])->assertStatus(422);
        $this->api($token)->patchJson('/api/v1/status-page', ['enabled' => true, 'title' => 'Acme status', 'notice' => 'Planned upgrade on Sunday.',
            'components' => [['name' => 'API', 'domain_id' => $api->id], ['name' => 'Website', 'domain_id' => $web->id]]])->assertOk();

        $res = $this->getJson('/api/v1/status')->assertOk();
        $page = $res->json('data');
        $this->assertSame(['Acme status', 'Planned upgrade on Sunday.', 'down'], [$page['title'], $page['notice'], $page['overall']]);
        $this->assertSame(['API', 'down', 90.0], [$page['components'][0]['name'], $page['components'][0]['state'], (float) $page['components'][0]['uptime_pct']]);
        $this->assertSame(['Website', 'operational', 100.0], [$page['components'][1]['name'], $page['components'][1]['state'], (float) $page['components'][1]['uptime_pct']]);
        $this->assertCount(90, $page['components'][0]['days']);
        $this->assertNull($page['components'][1]['days'][0]['uptime_pct'], 'a day without checks is unknown, not 100%');
        $this->assertSame([['component' => 'API', 'state' => 'ongoing']], array_map(fn ($i) => array_intersect_key($i, array_flip(['component', 'state'])), $page['incidents']));
        $body = $res->getContent();
        foreach (['internal-host', '10.0.0.5', 'Connection refused', $api->id] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        // A viewer may look at the settings but not change them.
        [, $viewer] = $this->member($org, 'viewer');
        $this->api($viewer)->patchJson('/api/v1/status-page', ['enabled' => false])->assertForbidden();
    }
}
