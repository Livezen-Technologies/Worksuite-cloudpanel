<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Job;
use App\Models\Volume;
use Tests\TestCase;

class CustomerFileManagerTest extends TestCase
{
    /** A customer app on a shared-pool server: the server and its volume rows belong to the platform. */
    private function setUpSharedApp(): array
    {
        [, $platform, $platformToken] = $this->org();
        $server = $this->server($platform);
        [$secret] = $this->enrollAgent($server, $platformToken);
        $this->heartbeat($secret, ['volumes' => [['name' => 'app-shop-data', 'driver' => 'local', 'mountpoint' => '/var/lib/docker/volumes/app-shop-data/_data']]]);
        [, $customer, $token] = $this->org();
        $app = Application::allTenants()->create(['organization_id' => $customer->id, 'server_id' => $server->id, 'name' => 'shop', 'source_type' => 'image', 'volume_path' => '/var/www/html', 'status' => 'running']);

        return [$app, $token, $secret, $platform, $customer];
    }

    public function test_customer_reaches_their_apps_storage_on_a_shared_server(): void
    {
        [$app, $token, $secret, $platform, $customer] = $this->setUpSharedApp();
        $this->assertSame($platform->id, Volume::allTenants()->where('name', 'app-shop-data')->sole()->organization_id);

        $this->api($token)->getJson('/api/v1/app/files')->assertOk()
            ->assertJsonPath('data.0.name', 'shop')->assertJsonPath('data.0.ready', true);

        $jobId = $this->api($token)->getJson("/api/v1/app/apps/{$app->id}/files?path=wp-content")->assertStatus(202)->json('data.job_id');
        // The job belongs to the customer, so they can follow it.
        $this->assertSame($customer->id, Job::allTenants()->find($jobId)->organization_id);

        $command = $this->heartbeat($secret)['commands'][0];
        $this->assertSame(['volume' => 'app-shop-data', 'path' => 'wp-content'], $command['payload']);
        $this->commandResult($secret, $command['id'], 'succeeded', ['output' => ['path' => 'wp-content', 'entries' => [], 'truncated' => false]])->assertOk();
        $this->api($token)->getJson("/api/v1/jobs/{$jobId}")->assertOk()->assertJsonPath('data.status', 'succeeded');
    }

    public function test_customers_cannot_reach_other_apps_or_change_ownership(): void
    {
        [$app, $token] = $this->setUpSharedApp();
        [, , $other] = $this->org();

        $this->api($other)->getJson("/api/v1/app/apps/{$app->id}/files")->assertNotFound();
        $this->api($token)->postJson("/api/v1/app/apps/{$app->id}/files/chown", ['paths' => ['index.php'], 'uid' => 0])->assertNotFound(); // no such customer route
        // The admin routes take a volume id the customer does not own.
        $volume = Volume::allTenants()->where('name', 'app-shop-data')->sole();
        $this->api($token)->getJson("/api/v1/volumes/{$volume->id}/files")->assertNotFound();
    }

    public function test_apps_without_storage_or_not_yet_reported(): void
    {
        [$app, $token, , , $customer] = $this->setUpSharedApp();
        $stateless = Application::allTenants()->create(['organization_id' => $customer->id, 'server_id' => $app->server_id, 'name' => 'api', 'source_type' => 'image', 'status' => 'running']);
        $pending = Application::allTenants()->create(['organization_id' => $customer->id, 'server_id' => $app->server_id, 'name' => 'blog', 'source_type' => 'image', 'volume_path' => '/data', 'status' => 'deploying']);

        $this->api($token)->getJson("/api/v1/app/apps/{$stateless->id}/files")->assertNotFound();
        $this->api($token)->getJson("/api/v1/app/apps/{$pending->id}/files")->assertStatus(409);
        $this->api($token)->getJson('/api/v1/app/files')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'blog')->assertJsonPath('data.0.ready', false);
    }
}
