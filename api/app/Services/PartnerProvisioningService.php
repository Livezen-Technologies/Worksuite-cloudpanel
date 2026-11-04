<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\BackupArtifact;
use App\Models\Container;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\EnvVar;
use App\Models\Job;
use App\Models\PartnerAccount;
use App\Models\PartnerClient;
use App\Models\PartnerDeployment;
use App\Models\PartnerWebhook;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Partner provisioning (docs/PARTNER_API.md): WorkSuite Cloud deploys and
 * operates WorkSuite Business / CMS through this service. Everything a
 * partner does lands on the same hosting, deployment, container, domain and
 * backup services the customer panel uses, inside the partner customer's own
 * organisation — so isolation, placement, Traefik/SSL, health checks and the
 * tamper-evident audit log apply unchanged.
 */
class PartnerProvisioningService
{
    public const ACTIONS = ['start', 'stop', 'restart', 'upgrade', 'backup', 'restore', 'scale', 'redeploy'];

    public function __construct(
        private Tenant $tenant,
        private OrganizationService $organizations,
        private HostingService $hosting,
        private DeploymentService $deployments,
        private ContainerService $containers,
        private BackupService $backups,
        private DomainService $domains,
        private AuditLogger $audit,
    ) {}

    /** The panel organisation for a partner customer account, created on first use with a service owner. */
    public function account(PartnerClient $client, string $externalId, string $name): PartnerAccount
    {
        $existing = PartnerAccount::where('partner_client_id', $client->id)->where('external_account_id', $externalId)->first();
        if ($existing) {
            if ($existing->name !== $name) {
                $existing->update(['name' => $name]);
            }

            return $existing;
        }

        return DB::transaction(function () use ($client, $externalId, $name) {
            // The service owner never signs in: random unusable password, a reserved address.
            $user = User::create([
                'name' => "{$client->name} service ({$externalId})",
                'email' => 'partner-'.Str::lower(Str::random(12)).'@partner.invalid',
                'password' => Str::random(64),
            ]);
            $org = $this->organizations->create($name, $user);
            $plan = $this->partnerPlan();
            Subscription::withoutGlobalScope('organization')->where('organization_id', $org->id)->update(['plan_id' => $plan->id]);

            return PartnerAccount::create([
                'partner_client_id' => $client->id, 'external_account_id' => $externalId,
                'organization_id' => $org->id, 'service_user_id' => $user->id, 'name' => $name,
            ]);
        });
    }

    public function partnerPlan(): Plan
    {
        return Plan::updateOrCreate(['code' => config('partner.plan_code')], config('partner.plan'));
    }

    public static function sizeFor(int $storageGb): string
    {
        foreach (config('partner.sizes') as $size => $gb) {
            if ($storageGb <= $gb) {
                return $size;
            }
        }

        return array_key_last(config('partner.sizes'));
    }

    /** Validate, record and start a deployment. Repeating the same external_id returns the existing one. */
    public function deploy(PartnerClient $client, array $in): PartnerDeployment
    {
        $existing = PartnerDeployment::where('partner_client_id', $client->id)->where('external_id', $in['external_id'])->first();
        if ($existing) {
            return $existing;
        }
        $template = config('partner.products')[$in['product']];
        $account = $this->account($client, $in['account']['id'], $in['account']['name']);
        $size = self::sizeFor((int) $in['storage_gb']);

        return $this->tenant->as($account->organization_id, function () use ($client, $in, $template, $account, $size) {
            $env = [
                ['key' => 'WORKSUITE_PRODUCT', 'value' => $in['product'], 'secret' => false],
                ['key' => 'WORKSUITE_WORKSPACE_NAME', 'value' => $in['workspace_name'], 'secret' => false],
                ['key' => 'WORKSUITE_CLOUD_ACCOUNT_ID', 'value' => $in['account']['id'], 'secret' => false],
                ['key' => 'WORKSUITE_ADMIN_EMAIL', 'value' => $in['admin']['email'], 'secret' => false],
                ['key' => 'WORKSUITE_ADMIN_NAME', 'value' => $in['admin']['name'] ?? '', 'secret' => false],
                ['key' => 'WORKSUITE_STORAGE_GB', 'value' => (string) $in['storage_gb'], 'secret' => false],
            ];
            // SSO: the instance signs people in with WorkSuite Cloud (OIDC). Passwords never travel here.
            foreach (($in['sso'] ?? []) as $k => $v) {
                $env[] = ['key' => 'OIDC_'.strtoupper($k), 'value' => (string) $v, 'secret' => $k === 'client_secret'];
            }
            foreach (($in['env'] ?? []) as $k => $v) {
                $env[] = ['key' => $k, 'value' => (string) $v, 'secret' => true];
            }
            $source = ['type' => 'template', 'template' => $template];
            $tpl = config('platform.app_templates')[$template];
            if (! empty($in['version'])) {
                $source = ['type' => 'image', 'image' => self::imageAt($tpl['image'], $in['version'])];
            }
            $result = $this->hosting->host(User::findOrFail($account->service_user_id), [
                'name' => $in['subdomain'], 'source' => $source, 'region' => $in['region'] ?? null, 'size' => $size,
                'port' => $tpl['port'], 'volume_path' => $tpl['volume_path'], 'env' => $env,
            ]);
            $dep = PartnerDeployment::create([
                'partner_client_id' => $client->id, 'partner_account_id' => $account->id, 'organization_id' => $account->organization_id,
                'application_id' => $result['app']['id'], 'external_id' => $in['external_id'], 'product' => $in['product'],
                'workspace_name' => $in['workspace_name'], 'subdomain' => $in['subdomain'], 'region' => $in['region'] ?? null,
                'size' => $size, 'storage_gb' => (int) $in['storage_gb'], 'version' => $in['version'] ?? null,
                'admin_email' => $in['admin']['email'], 'admin_name' => $in['admin']['name'] ?? null,
                'status' => 'provisioning', 'last_job_id' => $result['job_id'],
                'meta' => ['entitlement' => $in['entitlement'] ?? null],
            ]);
            if (! empty($in['custom_domain'])) {
                $this->addDomain($dep, $in['custom_domain']);
            }
            $this->log('partner.deployment.create', $dep, ['product' => $dep->product, 'subdomain' => $dep->subdomain]);
            $this->enqueue($dep, 'deployment.provisioning');

            return $dep->fresh();
        });
    }

    public static function imageAt(string $image, string $version): string
    {
        $base = preg_replace('/:[^:\/]+$/', '', $image);

        return $base.':'.$version;
    }

    /** Run one management action. Returns the job (or null for synchronous ones) and a summary. */
    public function act(PartnerDeployment $dep, string $action, array $params = []): array
    {
        $this->assertOperable($dep, $action);

        return $this->tenant->as($dep->organization_id, function () use ($dep, $action, $params) {
            $app = $this->app($dep);
            $user = User::find($dep->account->service_user_id);
            $container = Container::where('application_id', $app->id)->orderByDesc('updated_at')->first();
            $job = match ($action) {
                'start', 'stop', 'restart' => $container
                    ? $this->containers->perform($container, $action)
                    : throw ApiException::conflict('The deployment has no container yet; wait for provisioning to finish.'),
                'redeploy' => $this->deployments->start($app, $user, 'redeploy')->job,
                'upgrade' => (function () use ($app, $user, $dep, $params) {
                    $tpl = config('platform.app_templates')[config('partner.products')[$dep->product]];
                    $app->update(['image' => self::imageAt($app->image ?: $tpl['image'], $params['version'])]);
                    $dep->update(['version' => $params['version']]);

                    return $this->deployments->start($app->fresh(), $user, 'redeploy')->job;
                })(),
                'scale' => (function () use ($app, $user, $dep, $params) {
                    $size = self::sizeFor((int) $params['storage_gb']);
                    $app->update(['size' => $size]);
                    EnvVar::updateOrCreate(['application_id' => $app->id, 'key' => 'WORKSUITE_STORAGE_GB'], ['value' => (string) $params['storage_gb'], 'secret' => false]);
                    $dep->update(['size' => $size, 'storage_gb' => (int) $params['storage_gb']]);

                    return $this->deployments->start($app->fresh(), $user, 'redeploy')->job;
                })(),
                'backup' => Job::find($this->backups->run(null, 'application', $app->id, 'manual')->job_id),
                'restore' => (function () use ($app, $user, $params) {
                    $artifact = BackupArtifact::where('application_id', $app->id)->findOrFail($params['backup_id']);
                    $restore = $this->backups->restore($artifact, $user?->id, (bool) ($params['backup_first'] ?? true));

                    return Job::find($restore->job_id);
                })(),
            };
            if ($action === 'stop') {
                $dep->update(['status' => 'stopped']);
            } elseif (in_array($action, ['start', 'restart', 'redeploy', 'upgrade', 'scale'], true) && $dep->status !== 'provisioning') {
                $dep->update(['status' => 'provisioning', 'status_reason' => $action]);
            }
            $dep->update(['last_job_id' => $job?->id]);
            $this->log("partner.deployment.{$action}", $dep, $params);
            $this->enqueue($dep->fresh(), "deployment.action.{$action}", ['job_id' => $job?->id]);

            return ['job_id' => $job?->id, 'status' => $job?->status, 'action' => $action];
        });
    }

    /** Subscription-driven lifecycle: suspend keeps data, resume restores, terminate retires (data kept for the retention window). */
    public function lifecycle(PartnerDeployment $dep, string $to, string $reason = ''): PartnerDeployment
    {
        if ($dep->status === 'terminated') {
            throw ApiException::conflict('This deployment has been terminated.');
        }

        return $this->tenant->as($dep->organization_id, function () use ($dep, $to, $reason) {
            $app = $dep->application;
            $container = $app ? Container::where('application_id', $app->id)->first() : null;
            switch ($to) {
                case 'suspend':
                    if ($container) {
                        $this->containers->perform($container, 'stop');
                    }
                    $dep->update(['status' => 'suspended', 'status_reason' => $reason ?: 'suspended', 'suspended_at' => now()]);
                    break;
                case 'resume':
                    if ($container) {
                        $this->containers->perform($container, 'start');
                    }
                    $dep->update(['status' => $container ? 'provisioning' : 'failed', 'status_reason' => 'resumed', 'suspended_at' => null]);
                    break;
                case 'terminate':
                    $app?->retire();
                    $dep->update(['status' => 'terminated', 'status_reason' => $reason ?: 'terminated', 'terminated_at' => now()]);
                    break;
            }
            $this->log("partner.deployment.{$to}", $dep, ['reason' => $reason]);
            $this->enqueue($dep->fresh(), "deployment.{$to}");

            return $dep->fresh();
        });
    }

    public function addDomain(PartnerDeployment $dep, string $name): array
    {
        return $this->tenant->as($dep->organization_id, function () use ($dep, $name) {
            $app = $this->app($dep);
            $domain = $this->domains->create(['name' => $name, 'application_id' => $app->id, 'target_port' => $app->port]);
            $dep->update(['custom_domain' => $domain->name]);
            $this->log('partner.domain.create', $dep, ['domain' => $domain->name]);

            return ['domain' => $domain->name, 'records' => $this->domains->expectedRecords($domain), 'dns_status' => $domain->dns_status, 'https_status' => $domain->https_status];
        });
    }

    public function verifyDomain(PartnerDeployment $dep): array
    {
        return $this->tenant->as($dep->organization_id, function () use ($dep) {
            $domain = Domain::where('application_id', $dep->application_id)->where('platform_subdomain', false)->firstOrFail();
            $out = $this->domains->verify($domain);
            $this->enqueue($dep, 'deployment.domain');

            return $out;
        });
    }

    public function removeDomain(PartnerDeployment $dep): void
    {
        $this->tenant->as($dep->organization_id, function () use ($dep) {
            Domain::where('application_id', $dep->application_id)->where('platform_subdomain', false)->get()->each->delete();
            $dep->update(['custom_domain' => null]);
            $this->log('partner.domain.remove', $dep, []);
        });
    }

    public function logs(PartnerDeployment $dep, int $tail): array
    {
        return $this->tenant->as($dep->organization_id, function () use ($dep, $tail) {
            $app = $this->app($dep);
            $container = Container::where('application_id', $app->id)->first();
            $last = Deployment::where('application_id', $app->id)->latest()->first();

            return [
                'deployment' => $last ? ['status' => $last->status, 'lines' => $last->logs_tail ?? []] : null,
                'container' => $container ? $this->containers->logs($container, max(1, min(2000, $tail))) : null,
            ];
        });
    }

    public function backupsOf(PartnerDeployment $dep): array
    {
        if (! $dep->application_id) {
            return [];
        }

        return BackupArtifact::withoutGlobalScope('organization')->where('application_id', $dep->application_id)->latest()->limit(50)->get()
            ->map(fn (BackupArtifact $a) => [
                'id' => $a->id, 'size_mb' => $a->size_mb, 'verified' => (bool) $a->verified,
                'created_at' => $a->created_at?->toIso8601ZuluString(), 'expires_at' => $a->expires_at?->toIso8601ZuluString(),
            ])->all();
    }

    /** Work out the deployment's status from its job, container and domains. Persisted; returns whether it changed. */
    public function refresh(PartnerDeployment $dep): bool
    {
        if (in_array($dep->status, ['suspended', 'terminated'], true)) {
            return false;
        }
        $before = $dep->status;
        $this->tenant->as($dep->organization_id, function () use ($dep) {
            $app = $dep->application;
            if (! $app) {
                $dep->status = 'failed';
                $dep->status_reason = 'application missing';

                return;
            }
            $job = $dep->last_job_id ? Job::withoutGlobalScope('organization')->find($dep->last_job_id) : null;
            $state = $this->hosting->toApi($app)['state'];
            if ($job && in_array($job->status, ['queued', 'running'], true)) {
                $dep->status = $dep->status === 'stopped' ? 'stopped' : 'provisioning';
            } elseif ($job && $job->status === 'failed') {
                $dep->status = 'failed';
                $dep->status_reason = Str::limit((string) $job->error, 250);
            } elseif ($state === 'running') {
                $dep->status = 'running';
                $dep->status_reason = null;
            } elseif (in_array($state, ['stopped', 'exited'], true)) {
                $dep->status = 'stopped';
            } elseif ($state === 'failed') {
                $dep->status = 'failed';
            }
        });
        $dep->save();

        return $before !== $dep->status;
    }

    /** The partner-facing view of a deployment. */
    public function present(PartnerDeployment $dep): array
    {
        $app = $dep->application;
        $view = $app ? $this->tenant->as($dep->organization_id, fn () => $this->hosting->toApi($app, true)) : null;
        $job = $dep->last_job_id ? Job::withoutGlobalScope('organization')->find($dep->last_job_id) : null;
        $domains = collect($view['domains'] ?? []);
        $custom = $domains->first(fn ($d) => $d['name'] === $dep->custom_domain);

        return [
            'id' => $dep->id,
            'external_id' => $dep->external_id,
            'account_id' => $dep->account?->external_account_id,
            'product' => $dep->product,
            'workspace_name' => $dep->workspace_name,
            'status' => $dep->status,
            'status_reason' => $dep->status_reason,
            'url' => $dep->custom_domain && ($custom['https_status'] ?? null) === 'active' ? 'https://'.$dep->custom_domain : ($view['address'] ?? null),
            'platform_url' => $view['address'] ?? null,
            'subdomain' => $dep->subdomain,
            'custom_domain' => $dep->custom_domain ? [
                'name' => $dep->custom_domain,
                'dns_status' => $custom['dns_status'] ?? 'pending',
                'https_status' => $custom['https_status'] ?? 'pending',
            ] : null,
            'ssl' => ['platform' => ($domains->first()['https_status'] ?? 'pending'), 'custom' => $custom['https_status'] ?? null],
            'region' => $dep->region,
            'size' => $dep->size,
            'storage_gb' => $dep->storage_gb,
            'version' => $dep->version,
            'resources' => [
                'cpu_pct' => $view['cpu_pct'] ?? null,
                'ram_mb' => $view['ram_mb'] ?? null,
                'ram_limit_mb' => $view['ram_limit_mb'] ?? null,
                'storage_gb' => $view['storage_gb'] ?? null,
            ],
            'health' => $view['state'] ?? null,
            'last_job' => $job ? ['id' => $job->id, 'type' => $job->type, 'status' => $job->status, 'progress' => $job->progress, 'stages' => $job->stages, 'error' => $job->error] : null,
            'suspended_at' => $dep->suspended_at?->toIso8601ZuluString(),
            'terminated_at' => $dep->terminated_at?->toIso8601ZuluString(),
            'created_at' => $dep->created_at?->toIso8601ZuluString(),
            'updated_at' => $dep->updated_at?->toIso8601ZuluString(),
        ];
    }

    /** Queue a signed webhook to the partner (outbox; `partner:webhooks` delivers). */
    public function enqueue(PartnerDeployment $dep, string $event, array $extra = []): ?PartnerWebhook
    {
        $client = $dep->client;
        if (! $client?->webhook_url) {
            return null;
        }
        $state = $this->present($dep);
        $dep->forceFill(['reported_hash' => hash('sha256', json_encode([$state['status'], $state['health'], $state['url'], $state['ssl'], $state['custom_domain']]))])->saveQuietly();

        return PartnerWebhook::create([
            'partner_client_id' => $client->id, 'partner_deployment_id' => $dep->id, 'event' => $event,
            'payload' => ['event' => $event, 'occurred_at' => now()->toIso8601ZuluString(), 'deployment' => $state] + $extra,
            'next_attempt_at' => now(),
        ]);
    }

    /** Periodic: refresh every live deployment and emit an event where anything visible changed. */
    public function sync(): int
    {
        $sent = 0;
        PartnerDeployment::whereNotIn('status', ['terminated'])->with('client')->chunkById(100, function ($deps) use (&$sent) {
            foreach ($deps as $dep) {
                try {
                    $this->refresh($dep);
                    $state = $this->present($dep);
                    $hash = hash('sha256', json_encode([$state['status'], $state['health'], $state['url'], $state['ssl'], $state['custom_domain']]));
                    if ($hash !== $dep->reported_hash) {
                        $this->enqueue($dep, 'deployment.updated') && $sent++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        return $sent;
    }

    private function app(PartnerDeployment $dep): Application
    {
        return Application::where('kind', 'customer')->find($dep->application_id)
            ?? throw ApiException::conflict('The deployment has no running application.');
    }

    private function assertOperable(PartnerDeployment $dep, string $action): void
    {
        if ($dep->status === 'terminated') {
            throw ApiException::conflict('This deployment has been terminated.');
        }
        if ($dep->status === 'suspended' && ! in_array($action, ['backup'], true)) {
            throw ApiException::conflict('This deployment is suspended; renew the subscription to manage it.');
        }
    }

    private function log(string $action, PartnerDeployment $dep, array $meta): void
    {
        $this->audit->log($action, 'PartnerDeployment', $dep->id, $meta + ['external_id' => $dep->external_id], $dep->organization_id, null, 'partner:'.$dep->client?->name);
    }
}
