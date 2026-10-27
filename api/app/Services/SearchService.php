<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Container;
use App\Models\Domain;
use App\Models\Server;

/** DASH-008 command palette. */
class SearchService
{
    private const PAGES = [
        'Dashboard' => '/admin', 'Servers' => '/admin/servers', 'Containers' => '/admin/containers', 'Applications' => '/admin/applications',
        'Compose' => '/admin/compose', 'Images' => '/admin/images', 'Networks' => '/admin/networks', 'Volumes' => '/admin/volumes',
        'Domains' => '/admin/domains', 'Databases' => '/admin/databases', 'Backups' => '/admin/backups', 'Monitoring' => '/admin/monitoring',
        'Logs' => '/admin/logs', 'Automation' => '/admin/automation', 'Users' => '/admin/users', 'Settings' => '/admin/settings',
    ];

    public function search(string $q): array
    {
        $q = trim($q);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $results = collect(self::PAGES)->filter(fn ($url, $label) => $q === '' || stripos($label, $q) !== false)
            ->map(fn ($url, $label) => ['type' => 'page', 'id' => null, 'label' => $label, 'sublabel' => null, 'url' => $url])->values();
        if ($q === '') {
            return $results->all();
        }
        $results = $results
            ->concat(Server::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('hostname', 'like', $like)->orWhere('ip', 'like', $like))->limit(5)->get()
                ->map(fn ($s) => ['type' => 'server', 'id' => $s->id, 'label' => $s->name, 'sublabel' => $s->ip, 'url' => "/admin/servers/{$s->id}"]))
            ->concat(Container::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('image', 'like', $like))->limit(5)->get()
                ->map(fn ($c) => ['type' => 'container', 'id' => $c->id, 'label' => $c->name, 'sublabel' => $c->image, 'url' => "/admin/containers/{$c->id}"]))
            ->concat(Application::where('name', 'like', $like)->limit(5)->get()
                ->map(fn ($a) => ['type' => 'application', 'id' => $a->id, 'label' => $a->name, 'sublabel' => $a->source_type, 'url' => "/admin/applications/{$a->id}"]))
            ->concat(Domain::where('name', 'like', $like)->limit(5)->get()
                ->map(fn ($d) => ['type' => 'domain', 'id' => $d->id, 'label' => $d->name, 'sublabel' => $d->https_status, 'url' => "/admin/domains/{$d->id}"]));

        return $results->values()->all();
    }
}
