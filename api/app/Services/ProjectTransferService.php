<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * PRJ-9: hand a project over to a hosting customer. The project, its environments and
 * applications move to the customer's organisation together with everything that hangs
 * off them (containers, domains and certificates, routes, env vars, releases, deployments,
 * cron jobs, backups, firewall settings), so the customer can manage it from their panel.
 * Staff keep seeing it because they see every tenant. Servers stay where they are.
 * Billing usage, metrics and terminal history are history and stay with the old owner.
 */
class ProjectTransferService
{
    /** Tables keyed by the moved applications. */
    private const BY_APP = ['containers', 'domains', 'env_vars', 'deployments', 'releases', 'scaling_events', 'scheduled_jobs', 'backup_jobs', 'backup_artifacts', 'wp_sites', 'routes'];

    /** Tables keyed by the moved domains. */
    private const BY_DOMAIN = ['certificates', 'dns_checks', 'firewall_countries', 'firewall_events', 'firewall_ip_lists', 'firewall_protected_paths', 'firewall_rules', 'uptime_checks'];

    public function __construct(private AuditLogger $audit) {}

    public function preview(Project $project): array
    {
        $apps = Application::allTenants()->where('project_id', $project->id)->pluck('id');

        return [
            'environments' => Environment::allTenants()->where('project_id', $project->id)->count(),
            'applications' => $apps->count(),
            'domains' => DB::table('domains')->whereIn('application_id', $apps)->count(),
            'containers' => DB::table('containers')->whereIn('application_id', $apps)->count(),
        ];
    }

    public function handOver(Project $project, Organization $to, ?string $userId = null, ?string $actor = null): array
    {
        $from = $project->organization_id;
        if ($from === $to->id) {
            throw ApiException::conflict('The project already belongs to '.$to->name.'.');
        }
        $counts = DB::transaction(function () use ($project, $to) {
            $apps = Application::allTenants()->where('project_id', $project->id)->pluck('id');
            $domains = DB::table('domains')->whereIn('application_id', $apps)->pluck('id');
            $containers = DB::table('containers')->whereIn('application_id', $apps)->pluck('id');
            $routes = DB::table('routes')->whereIn('application_id', $apps)->orWhereIn('domain_id', $domains)->pluck('id');
            $set = ['organization_id' => $to->id];
            $n = ['applications' => $apps->count(), 'domains' => $domains->count()];

            DB::table('projects')->where('id', $project->id)->update($set);
            $n['environments'] = DB::table('environments')->where('project_id', $project->id)->update($set);
            DB::table('applications')->whereIn('id', $apps)->update($set + ['kind' => 'customer']);
            foreach (self::BY_APP as $t) {
                DB::table($t)->whereIn('application_id', $apps)->update($set);
            }
            foreach (self::BY_DOMAIN as $t) {
                DB::table($t)->whereIn('domain_id', $domains)->update($set);
            }
            DB::table('routes')->whereIn('id', $routes)->update($set);
            DB::table('proxy_rules')->whereIn('route_id', $routes)->update($set);
            DB::table('databases')->whereIn('container_id', $containers)->update($set);
            DB::table('volume_mounts')->whereIn('container_id', $containers)->update($set);
            $n['containers'] = $containers->count();

            return $n;
        });
        $meta = ['project' => $project->name, 'from' => $from, 'to' => $to->id, 'to_name' => $to->name] + $counts;
        $this->audit->log('project.handover', 'projects', $project->id, $meta, $from, $userId, $actor);
        $this->audit->log('project.handover', 'projects', $project->id, $meta, $to->id, $userId, $actor);

        return $counts;
    }
}
