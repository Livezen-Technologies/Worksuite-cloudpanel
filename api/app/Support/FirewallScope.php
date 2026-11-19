<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Builder;

/** Which firewall rules and IP entries apply to a domain: organisation-wide, its project, its app, or itself. */
final class FirewallScope
{
    public static function forDomain(Builder $q, Domain $domain): Builder
    {
        $app = $domain->application_id ? Application::allTenants()->find($domain->application_id) : null;

        return $q->where(function ($q) use ($domain, $app) {
            $q->where(fn ($q) => $q->whereNull('domain_id')->whereNull('application_id')->whereNull('project_id'))
                ->orWhere('domain_id', $domain->id);
            if ($app) {
                $q->orWhere('application_id', $app->id);
                $app->project_id && $q->orWhere('project_id', $app->project_id);
            }
        });
    }

    /** Validation rules: at most one of domain, application or project. */
    public static function rules(): array
    {
        return [
            'domain_id' => 'nullable|uuid|exists:domains,id',
            'application_id' => 'nullable|uuid|exists:applications,id',
            'project_id' => 'nullable|uuid|exists:projects,id',
        ];
    }

    public static function assertSingle(array $data): void
    {
        if (count(array_filter([$data['domain_id'] ?? null, $data['application_id'] ?? null, $data['project_id'] ?? null])) > 1) {
            throw \App\Exceptions\ApiException::validation(['scope' => ['Choose one scope: a domain, an application or a project.']]);
        }
    }

    public static function label(object $m): string
    {
        return match (true) {
            (bool) $m->domain_id => 'domain '.(Domain::allTenants()->whereKey($m->domain_id)->value('name') ?? ''),
            (bool) $m->application_id => 'app '.(Application::allTenants()->whereKey($m->application_id)->value('name') ?? ''),
            (bool) $m->project_id => 'project '.(\App\Models\Project::allTenants()->whereKey($m->project_id)->value('name') ?? ''),
            default => 'all domains',
        };
    }
}
