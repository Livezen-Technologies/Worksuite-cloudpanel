<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\BackupArtifact;
use App\Models\BackupJob;
use App\Models\BackupPolicy;
use App\Models\Certificate;
use App\Models\Container;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Image;
use App\Models\Invoice;
use App\Models\Metric;
use App\Models\Network;
use App\Models\NotificationChannel;
use App\Models\Release;
use App\Models\Server;
use App\Models\StoragePool;
use App\Models\User;
use App\Models\Volume;
use App\Services\OrganizationService;
use App\Support\Tenant;
use Illuminate\Database\Seeder;

/** Demo organisation for the frontend: owner admin@example.com / password. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
        if (User::where('email', 'admin@example.com')->exists()) {
            return;
        }
        $owner = User::create(['name' => 'Alex Admin', 'email' => 'admin@example.com', 'password' => 'password', 'email_verified_at' => now()]);
        $org = app(OrganizationService::class)->create('Demo Organisation', $owner);
        // The demo owner is also the platform super admin.
        $org->memberships()->where('user_id', $owner->id)->update(['role' => 'super_admin']);
        foreach ([['dev@example.com', 'Dana Developer', 'developer'], ['viewer@example.com', 'Vic Viewer', 'viewer'], ['billing@example.com', 'Bill Billing', 'billing'], ['owner@example.com', 'Olivia Owner', 'owner']] as [$email, $name, $role]) {
            $u = User::create(['name' => $name, 'email' => $email, 'password' => 'password', 'current_organization_id' => $org->id]);
            \App\Models\Membership::create(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);
        }

        app(Tenant::class)->as($org->id, function () use ($owner) {
            $defs = [
                ['prod-web-01', '10.0.1.11', 'production', 'eu-central', ['web', 'edge'], 34, 61, 48, 15],
                ['prod-web-02', '10.0.1.12', 'production', 'eu-central', ['web'], 41, 58, 52, 15],
                ['prod-db-01', '10.0.1.21', 'production', 'eu-west', ['db'], 22, 78, 71, 20],
                ['staging-01', '10.0.2.11', 'staging', 'eu-central', ['staging'], 12, 35, 30, 25],
                ['edge-us-01', '10.0.3.11', 'production', 'us-east', ['edge'], 93, 92, 88, 30],
                ['backup-01', '10.0.4.11', 'production', 'eu-west', ['backup'], 5, 20, 64, 400],
            ];
            $servers = [];
            foreach ($defs as $i => [$name, $ip, $env, $region, $tags, $cpu, $ram, $disk, $age]) {
                $servers[$name] = Server::create([
                    'name' => $name, 'hostname' => $name.'.demo.internal', 'ip' => $ip, 'environment' => $env, 'region' => $region, 'tags' => $tags,
                    'os' => 'Ubuntu 24.04 LTS', 'kernel' => '6.8.0-45-generic', 'arch' => 'amd64', 'docker_version' => '27.3.1',
                    'cpu_cores' => [8, 8, 16, 4, 4, 2][$i], 'ram_mb' => [16384, 16384, 65536, 8192, 8192, 4096][$i], 'disk_gb' => [160, 160, 960, 80, 80, 2000][$i],
                    'cpu_pct' => $cpu, 'ram_pct' => $ram, 'disk_pct' => $disk, 'agent_version' => '1.0.0',
                    'last_heartbeat_at' => now()->subSeconds($age), 'shared_pool' => $env === 'production' && $tags[0] === 'web',
                    'maintenance' => false,
                ]);
            }
            $servers['staging-01']->update(['maintenance' => true]);
            Server::create(['name' => 'new-node-01', 'environment' => 'production', 'region' => 'eu-central', 'tags' => []]);

            $containers = [
                ['prod-web-01', 'traefik', 'traefik:v3.1', 'running', 'healthy', 3.2, 64, ['80:80/tcp', '443:443/tcp']],
                ['prod-web-01', 'app-shop', 'ghcr.io/demo/shop:1.8.2', 'running', 'healthy', 18.5, 412, []],
                ['prod-web-01', 'app-blog', 'ghost:5', 'running', 'healthy', 4.1, 280, []],
                ['prod-web-02', 'app-shop-2', 'ghcr.io/demo/shop:1.8.2', 'running', 'healthy', 16.0, 398, []],
                ['prod-web-02', 'worker-queue', 'ghcr.io/demo/worker:2.0.0', 'running', 'unhealthy', 61.0, 760, []],
                ['prod-db-01', 'db-main', 'postgres:16', 'running', 'healthy', 22.4, 4096, []],
                ['prod-db-01', 'redis-cache', 'redis:7', 'running', 'healthy', 1.5, 128, []],
                ['staging-01', 'app-shop-staging', 'ghcr.io/demo/shop:1.9.0-rc1', 'exited', null, 0, 0, []],
                ['edge-us-01', 'traefik-us', 'traefik:v3.1', 'running', 'healthy', 8.0, 70, ['80:80/tcp', '443:443/tcp']],
                ['backup-01', 'old-migration', 'alpine:3.19', 'exited', null, 0, 0, []],
            ];
            foreach ($containers as $i => [$server, $name, $image, $state, $health, $cpu, $mem, $ports]) {
                Container::create(['server_id' => $servers[$server]->id, 'docker_id' => substr(hash('sha256', $name), 0, 64), 'name' => $name, 'image' => $image,
                    'state' => $state, 'health' => $health, 'cpu_pct' => $cpu, 'mem_mb' => $mem, 'ports' => $ports,
                    'started_at' => $state === 'running' ? now()->subHours(5 + $i * 7) : null, 'last_logs' => "starting {$name}\nlistening\nready",
                    'spec' => ['restart_policy' => 'unless-stopped', 'memory_mb' => 1024, 'security' => ['cap_drop' => ['ALL'], 'no_new_privileges' => true]]]);
            }
            foreach ($servers as $server) {
                foreach (['traefik:v3.1', 'postgres:16', 'redis:7', 'ghcr.io/demo/shop:1.8.2'] as $j => $tag) {
                    Image::create(['server_id' => $server->id, 'docker_id' => 'sha256:'.hash('sha256', $server->name.$tag), 'repository' => explode(':', $tag)[0], 'tags' => [$tag],
                        'digest' => 'sha256:'.hash('sha256', $tag), 'size_mb' => [150, 420, 40, 230][$j], 'scan_status' => $j % 2 ? 'scanned' : 'not_scanned',
                        'scan_result' => $j % 2 ? ['critical' => 0, 'high' => 1, 'medium' => 4, 'low' => 9, 'vulnerabilities' => []] : null]);
                }
                Network::create(['server_id' => $server->id, 'name' => 'bridge', 'driver' => 'bridge', 'subnet' => '172.17.0.0/16']);
                Network::create(['server_id' => $server->id, 'name' => 'proxy', 'driver' => 'bridge', 'subnet' => '172.20.0.0/16', 'exposure' => 'public']);
                StoragePool::create(['server_id' => $server->id, 'name' => $server->name.'-nvme', 'type' => 'nvme', 'capacity_gb' => $server->disk_gb ?? 100, 'used_gb' => (int) (($server->disk_gb ?? 100) * ($server->disk_pct ?? 10) / 100)]);
            }
            StoragePool::create(['name' => 'nas-01', 'type' => 'nfs', 'capacity_gb' => 8000, 'used_gb' => 3100]);
            StoragePool::create(['name' => 's3-backups', 'type' => 'object', 'capacity_gb' => 0, 'used_gb' => 840]);
            $dbVolume = Volume::create(['server_id' => $servers['prod-db-01']->id, 'name' => 'db-main-data', 'size_gb' => 42.5, 'mountpoint' => '/var/lib/docker/volumes/db-main-data/_data']);
            Volume::create(['server_id' => $servers['prod-web-01']->id, 'name' => 'app-blog-data', 'size_gb' => 2.1]);

            // Metrics for the last 24 h, every 5 minutes.
            foreach ($servers as $server) {
                for ($m = 288; $m >= 0; $m--) {
                    Metric::create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'recorded_at' => now()->subMinutes($m * 5),
                        'cpu_pct' => max(1, min(99, $server->cpu_pct + sin($m / 9) * 12)), 'ram_pct' => max(1, min(99, $server->ram_pct + cos($m / 13) * 6)),
                        'disk_pct' => $server->disk_pct, 'net_rx' => 1000000 * (300 - $m), 'net_tx' => 500000 * (300 - $m)]);
                }
            }

            $shop = Application::create(['name' => 'shop', 'kind' => 'admin', 'source_type' => 'git', 'source' => ['repo' => 'https://github.com/demo/shop.git', 'branch' => 'main'],
                'image' => 'ghcr.io/demo/shop:1.8.2', 'strategy' => 'rolling', 'environment' => 'production', 'server_id' => $servers['prod-web-01']->id, 'port' => 8080,
                'status' => 'running', 'last_deployed_at' => now()->subHours(3)]);
            $blog = Application::create(['name' => 'blog', 'kind' => 'customer', 'source_type' => 'template', 'source' => ['type' => 'template', 'template' => 'ghost'],
                'image' => 'ghost:5', 'environment' => 'production', 'server_id' => $servers['prod-web-01']->id, 'port' => 2368, 'size' => 'small', 'region' => 'eu-central',
                'volume_path' => '/var/lib/ghost/content', 'status' => 'running', 'last_deployed_at' => now()->subDay()]);
            Container::where('name', 'app-shop')->update(['application_id' => $shop->id]);
            Container::where('name', 'app-blog')->update(['application_id' => $blog->id]);
            foreach ([[$shop, ['1.8.0', '1.8.1', '1.8.2']], [$blog, ['5']]] as [$app, $versions]) {
                foreach ($versions as $v => $tag) {
                    $job = \App\Models\Job::create(['type' => 'deployment', 'status' => 'succeeded', 'progress' => 100, 'resource_type' => 'Application', 'resource_id' => $app->id, 'user_id' => $owner->id,
                        'stages' => collect($app->kind === 'customer' ? \App\Services\DeploymentService::CUSTOMER_STAGES : \App\Services\DeploymentService::ADMIN_STAGES)
                            ->map(fn ($s) => ['name' => $s, 'status' => 'succeeded', 'started_at' => now()->subDays(3 - $v)->toIso8601ZuluString(), 'finished_at' => now()->subDays(3 - $v)->addSeconds(20)->toIso8601ZuluString(), 'message' => null])->all(),
                        'started_at' => now()->subDays(3 - $v), 'finished_at' => now()->subDays(3 - $v)->addMinute()]);
                    $deployment = Deployment::create(['application_id' => $app->id, 'job_id' => $job->id, 'user_id' => $owner->id, 'status' => 'succeeded', 'server_id' => $app->server_id,
                        'image' => preg_replace('/:[^:]+$/', '', $app->image).':'.$tag, 'started_at' => now()->subDays(3 - $v), 'finished_at' => now()->subDays(3 - $v)->addMinute()]);
                    $release = Release::create(['application_id' => $app->id, 'deployment_id' => $deployment->id, 'version' => $v + 1, 'image' => $deployment->image,
                        'config' => ['image' => $deployment->image, 'port' => $app->port, 'env_keys' => [], 'env_encrypted' => null, 'volume_path' => $app->volume_path],
                        'status' => $v === count($versions) - 1 ? 'active' : 'superseded', 'created_at' => now()->subDays(3 - $v)]);
                    $deployment->update(['release_id' => $release->id]);
                    $app->update(['current_release_id' => $release->id]);
                }
            }

            $shopDomain = Domain::create(['name' => 'shop.example.com', 'application_id' => $shop->id, 'target_port' => 8080, 'dns_status' => 'verified', 'https_status' => 'active', 'www_redirect' => true]);
            $blogFree = Domain::create(['name' => 'blog.'.config('platform.domain'), 'application_id' => $blog->id, 'target_port' => 2368, 'platform_subdomain' => true, 'dns_status' => 'verified', 'https_status' => 'active']);
            Domain::create(['name' => 'blog.example.org', 'application_id' => $blog->id, 'target_port' => 2368]);
            Certificate::create(['domain_id' => $shopDomain->id, 'issuer' => 'letsencrypt', 'names' => ['shop.example.com', 'www.shop.example.com'], 'issued_at' => now()->subDays(80), 'expires_at' => now()->addDays(10)]);
            Certificate::create(['domain_id' => $blogFree->id, 'issuer' => 'letsencrypt', 'names' => [$blogFree->name], 'issued_at' => now()->subDays(20), 'expires_at' => now()->addDays(70)]);

            $policy = BackupPolicy::create(['name' => 'Main database nightly', 'target_type' => 'volume', 'target_id' => $dbVolume->id, 'method' => 'dump', 'schedule' => '0 3 * * *',
                'destination' => 's3', 'destination_config' => ['endpoint' => 'https://s3.eu-central-1.amazonaws.com', 'bucket' => 'demo-backups'], 'retention_days' => 30, 'recovery_key' => 'demo-recovery-key', 'last_status' => 'succeeded', 'last_run_at' => now()]);
            for ($d = 13; $d >= 0; $d--) {
                $ok = $d !== 4;
                $bj = BackupJob::create(['backup_policy_id' => $policy->id, 'server_id' => $servers['prod-db-01']->id, 'target_type' => 'volume', 'target_id' => $dbVolume->id, 'trigger' => 'schedule',
                    'status' => $ok ? 'succeeded' : 'failed', 'error' => $ok ? null : 'S3 endpoint timeout', 'started_at' => now()->subDays($d), 'finished_at' => now()->subDays($d)->addMinutes(4), 'created_at' => now()->subDays($d)]);
                if ($ok) {
                    BackupArtifact::create(['backup_job_id' => $bj->id, 'snapshot_id' => substr(hash('sha1', "snap{$d}"), 0, 8), 'repository' => 's3:demo-backups', 'size_mb' => 4200 + $d * 10, 'verified' => true, 'expires_at' => now()->addDays(30 - $d)]);
                }
            }
            $blogJob = BackupJob::create(['application_id' => $blog->id, 'server_id' => $servers['prod-web-01']->id, 'target_type' => 'application', 'target_id' => $blog->id, 'trigger' => 'schedule', 'status' => 'succeeded', 'finished_at' => now()->subHours(6)]);
            BackupArtifact::create(['backup_job_id' => $blogJob->id, 'application_id' => $blog->id, 'snapshot_id' => 'a1b2c3d4', 'repository' => '/var/lib/dockermgr/backups', 'size_mb' => 180, 'verified' => true, 'expires_at' => now()->addDays(7)]);

            NotificationChannel::create(['name' => 'Ops log', 'type' => 'log', 'config' => []]);
            $cpuRule = AlertRule::where('metric', 'cpu')->first();
            Alert::create(['alert_rule_id' => $cpuRule?->id, 'resource_type' => 'Server', 'resource_id' => $servers['edge-us-01']->id, 'severity' => 'critical', 'title' => 'CPU > 90% on edge-us-01', 'value' => 93]);
            $unhealthy = AlertRule::where('metric', 'container_unhealthy')->first();
            Alert::create(['alert_rule_id' => $unhealthy?->id, 'resource_type' => 'Container', 'resource_id' => Container::where('name', 'worker-queue')->value('id'), 'severity' => 'warning', 'title' => 'Container worker-queue is unhealthy']);
            foreach (range(1, 3) as $n) {
                $start = now()->subMonths($n)->startOfMonth();
                Invoice::create(['number' => 'INV-'.$start->format('Ym').'-0001', 'period_start' => $start, 'period_end' => $start->copy()->endOfMonth(), 'amount_cents' => 1700, 'status' => 'paid']);
            }
            $org = Tenant::current();
            $org->update(['payment_method' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2029]]);
        });
    }
}
