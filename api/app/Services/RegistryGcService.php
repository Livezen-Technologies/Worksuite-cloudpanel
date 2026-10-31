<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Registry;
use App\Models\Release;
use Illuminate\Support\Facades\Http;

/**
 * BLD: registry garbage collection. Images the panel pushed for an app are kept
 * for its current release and the last `keep` releases; older ones are deleted
 * from the registry with the Registry v2 API (DELETE /v2/<repo>/manifests/<digest>).
 * The registry frees the disk space on its own `garbage-collect` run. Releases whose
 * image is gone are marked, so a rollback to them says so instead of failing to pull.
 */
class RegistryGcService
{
    /** @return array{0:string,1:string,2:string}|null [host, repository, digest] for host/repo@sha256:… */
    public static function split(string $image): ?array
    {
        if (! preg_match('#^([^/]+)/([^@]+)@(sha256:[a-f0-9]{64})$#', $image, $m)) {
            return null;
        }

        return [$m[1], $m[2], $m[3]];
    }

    /** @return list<array{application:string, version:int, image:string, deleted:bool, error:?string}> */
    public function collect(Registry $registry, int $keep, bool $dryRun = false): array
    {
        $host = BuildService::registryHost($registry);
        $scheme = str_starts_with(strtolower(trim($registry->url)), 'http://') ? 'http' : 'https';
        $out = [];
        foreach (Application::query()->get(['id', 'name', 'current_release_id']) as $app) {
            $releases = Release::where('application_id', $app->id)->orderByDesc('version')->get();
            $kept = $releases->take($keep)->pluck('image')->push(optional($releases->firstWhere('id', $app->current_release_id))->image)->filter()->all();
            foreach ($releases->slice($keep) as $r) {
                $parts = $r->image ? self::split($r->image) : null;
                if (! $parts || $parts[0] !== $host || in_array($r->image, $kept, true) || ! empty($r->config['image_deleted_at'])) {
                    continue;
                }
                [, $repo, $digest] = $parts;
                $row = ['application' => $app->name, 'version' => $r->version, 'image' => $r->image, 'deleted' => false, 'error' => null];
                if (! $dryRun) {
                    $res = Http::withBasicAuth((string) $registry->username, (string) $registry->credentials)->timeout(20)
                        ->withHeaders(['Accept' => 'application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json'])
                        ->delete("{$scheme}://{$host}/v2/{$repo}/manifests/{$digest}");
                    $row['deleted'] = $res->status() === 202 || $res->status() === 404;
                    $row['error'] = $row['deleted'] ? null : ($res->status() === 405 ? 'the registry does not allow deletes (REGISTRY_STORAGE_DELETE_ENABLED)' : 'HTTP '.$res->status());
                    if ($row['deleted']) {
                        $r->forceFill(['config' => array_merge($r->config ?? [], ['image_deleted_at' => now()->toIso8601String()])])->save();
                    }
                }
                $out[] = $row;
            }
        }

        return $out;
    }

    public function collectAll(): int
    {
        $n = 0;
        foreach (Registry::allTenants()->where('type', 'private')->get() as $registry) {
            app(\App\Support\Tenant::class)->as($registry->organization_id, function () use ($registry, &$n) {
                $n += collect($this->collect($registry, (int) config('platform.builds.registry_keep', 5)))->where('deleted', true)->count();
            });
        }

        return $n;
    }
}
