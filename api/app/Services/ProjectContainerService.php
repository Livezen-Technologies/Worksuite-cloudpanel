<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Container;

/**
 * Groups containers under projects without touching application_id:
 * - an app's own containers take the app's project;
 * - any other container takes the project of the app that shares its Docker Compose project
 *   (label com.docker.compose.project) on the same server: the site's database, Redis, workers.
 * Containers that match no app keep no project. Never deletes, moves or restarts anything.
 */
class ProjectContainerService
{
    public function sync(): int
    {
        $apps = Application::allTenants()->whereNotNull('project_id')->get(['id', 'project_id', 'organization_id'])->keyBy('id');
        $containers = Container::allTenants()->get(['id', 'application_id', 'server_id', 'labels', 'project_id', 'organization_id']);
        $siteProject = [];
        foreach ($containers as $c) {
            $site = $this->site($c);
            if ($c->application_id && isset($apps[$c->application_id]) && $site) {
                $siteProject[$c->server_id.'|'.$site] ??= $apps[$c->application_id]->project_id;
            }
        }
        // Databases and Compose stacks that belong to a project bring their containers with them.
        $dbProject = \App\Models\Database::allTenants()->whereNotNull('project_id')->pluck('project_id', 'id');
        foreach (\App\Models\ComposeProject::allTenants()->whereNotNull('project_id')->get(['name', 'server_id', 'project_id']) as $cp) {
            $siteProject[$cp->server_id.'|'.$cp->name] = $cp->project_id;
            $siteProject[$cp->server_id.'|'.\Illuminate\Support\Str::slug($cp->name)] = $cp->project_id;
        }
        $changed = 0;
        foreach ($containers as $c) {
            $labels = (array) ($c->labels ?? []);
            $project = match (true) {
                ! empty($labels['io.dockermgr.project']) => $labels['io.dockermgr.project'],
                ! empty($labels['io.dockermgr.database']) && isset($dbProject[$labels['io.dockermgr.database']]) => $dbProject[$labels['io.dockermgr.database']],
                $c->application_id && isset($apps[$c->application_id]) => $apps[$c->application_id]->project_id,
                default => ($site = $this->site($c)) ? ($siteProject[$c->server_id.'|'.$site] ?? null) : null,
            };
            if ($project !== $c->project_id) {
                Container::allTenants()->whereKey($c->id)->update(['project_id' => $project]);
                $changed++;
            }
        }

        return $changed;
    }

    private function site(Container $c): ?string
    {
        $labels = (array) ($c->labels ?? []);

        return $labels['com.docker.compose.project'] ?? null;
    }
}
