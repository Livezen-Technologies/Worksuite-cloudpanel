<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Registry;
use App\Models\Secret;
use App\Models\Server;
use App\Support\Tenant;

/**
 * Image builds for Git/Dockerfile sources (APP-001/002). Untrusted builds run on
 * servers with the "builder" role, never on the control plane, and never on the
 * deploy target unless it is itself a builder and the organisation allows it (SRS 2.4).
 */
class BuildService
{
    public function needsBuild(Application $app): bool
    {
        return in_array($app->source_type, ['git', 'dockerfile'], true) && ! empty($app->source['repo']);
    }

    /** Registry the built image is pushed to (`source.push_registry_id`, else the platform build registry), or null for a local-only image. */
    public function pushRegistry(Application $app): ?Registry
    {
        $id = $app->source['push_registry_id'] ?? null;
        if ($id) {
            return Registry::find($id);
        }
        // Platform-wide build registry (BUILD_PUSH_REGISTRY*), used e.g. for customer Git apps.
        $platform = config('platform.builds.push_registry');
        if (! empty($platform['url'])) {
            return new Registry(['name' => 'platform', 'type' => 'private', 'url' => $platform['url'], 'username' => $platform['username'] ?? null, 'credentials' => $platform['password'] ?? null]);
        }

        return null;
    }

    public static function registryHost(Registry $registry): string
    {
        return rtrim(preg_replace('#^[a-z]+://#i', '', trim($registry->url)), '/');
    }

    /**
     * @param  Server|null  $target  the deploy target when known
     * @param  bool  $mustBeTarget  true when the image is not pushed and therefore has to be built where it runs
     */
    public function pickBuilder(Application $app, ?Server $target, bool $mustBeTarget): Server
    {
        $allowTarget = $target && $target->hasRole('builder')
            && (bool) Tenant::current()?->setting('security.allow_builds_on_deploy_targets');
        if ($mustBeTarget) {
            if (! $target) {
                throw ApiException::conflict('This application has no push registry, so it must be built on its deploy target, but no target server is known yet.', ['reason' => 'no_builder']);
            }
            if (! $allowTarget) {
                throw ApiException::conflict("No push registry is configured for {$app->name}, so the image would have to be built on the deploy target {$target->name}. Builds never run on deploy targets unless the server has the builder role and \"Allow builds on deploy targets\" is enabled. Set a push registry or change the server role/setting.", ['reason' => 'no_builder']);
            }
            if (! $target->isOnline()) {
                throw ApiException::conflict("Builder {$target->name} is not online.", ['reason' => 'no_builder']);
            }

            return $target;
        }

        $builder = Server::allTenants()
            ->where(fn ($q) => $q->where('organization_id', $app->organization_id)->orWhere('shared_pool', true))
            ->where('maintenance', false)
            ->get()
            ->filter(fn (Server $s) => $s->hasRole('builder') && $s->isOnline() && ($allowTarget || $s->id !== $target?->id))
            ->sortBy(fn (Server $s) => [$s->organization_id === $app->organization_id ? 0 : 1, $s->cpu_pct ?? 0])
            ->first();
        if (! $builder) {
            throw ApiException::conflict('No online builder server is available. Give a server the "builder" role (Servers → server → Settings).', ['reason' => 'no_builder']);
        }

        return $builder;
    }

    /** Builds the `image.build` payload; the Git token is resolved server-side from `source.git_secret_id`. */
    public function payload(Application $app, Deployment $deployment, ?Registry $registry): array
    {
        $source = $app->source ?? [];
        $slug = Tenant::current()?->slug ?? 'org';
        $tag = substr(str_replace('-', '', $deployment->id), 0, 12);
        $repository = $registry
            ? self::registryHost($registry).'/'.$slug.'/'.$app->name
            : config('platform.builds.local_repository').'/'.$slug.'-'.$app->name;

        $payload = [
            'repo' => (string) $source['repo'],
            'ref' => (string) ($source['ref'] ?? $source['branch'] ?? 'main'),
            'dockerfile_path' => (string) ($source['dockerfile_path'] ?? 'Dockerfile'),
            'context' => (string) ($source['context'] ?? '.'),
            'image_tag' => strtolower($repository).':'.$tag,
            'labels' => ['io.dockermgr.app' => $app->id, 'io.dockermgr.deployment' => $deployment->id],
        ];
        if (! empty($source['build_args'])) {
            $payload['build_args'] = array_map('strval', (array) $source['build_args']);
        }
        if (! empty($source['git_secret_id'])) {
            $secret = Secret::find($source['git_secret_id']) ?? throw ApiException::conflict('The Git credential secret referenced by this application no longer exists.');
            $payload['git_auth'] = array_filter(['username' => $source['git_username'] ?? null, 'token' => (string) $secret->value]);
        }
        if ($registry) {
            $payload['push'] = ['registry' => self::registryHost($registry), 'username' => (string) $registry->username, 'password' => (string) $registry->credentials];
        }

        return $payload;
    }
}
