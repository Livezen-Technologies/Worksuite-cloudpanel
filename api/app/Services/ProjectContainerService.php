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
        $changed = 0;
        foreach ($containers as $c) {
            $project = ($c->application_id && isset($apps[$c->application_id])) ? $apps[$c->application_id]->project_id
                : (($site = $this->site($c)) ? ($siteProject[$c->server_id.'|'.$site] ?? null) : null);
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
