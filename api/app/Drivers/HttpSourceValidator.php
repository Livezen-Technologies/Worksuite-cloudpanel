<?php

namespace App\Drivers;

use App\Contracts\SourceValidator;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/** Checks image reachability against the registry v2 API and Git via ls-remote. */
class HttpSourceValidator implements SourceValidator
{
    public function image(string $reference, ?array $auth = null): array
    {
        [$registry, $repository, $tag] = self::parse($reference);
        try {
            $headers = ['Accept' => 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json'];
            $url = "https://{$registry}/v2/{$repository}/manifests/{$tag}";
            $response = Http::timeout(8)->withHeaders($headers)->head($url);
            if ($response->status() === 401 && preg_match('/realm="([^"]+)",service="([^"]+)"/', $response->header('WWW-Authenticate'), $m)) {
                $tokenRequest = Http::timeout(8);
                if (! empty($auth['username'])) {
                    $tokenRequest = $tokenRequest->withBasicAuth($auth['username'], $auth['password'] ?? '');
                }
                $token = $tokenRequest->get($m[1], ['service' => $m[2], 'scope' => "repository:{$repository}:pull"])->json('token');
                $response = Http::timeout(8)->withHeaders($headers)->withToken((string) $token)->head($url);
            }
            if ($response->successful()) {
                return ['ok' => true, 'message' => 'Image found.', 'digest' => $response->header('Docker-Content-Digest') ?: null];
            }

            return ['ok' => false, 'message' => $response->status() === 401
                ? 'The registry refused access. Check the registry credentials.'
                : "We couldn't find the image {$reference}. Check the name and tag."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "We couldn't reach the registry {$registry}."];
        }
    }

    public function git(string $repository, string $branch): array
    {
        if (! preg_match('#^(https://|git@)[\w.@:/~-]+$#', $repository)) {
            return ['ok' => false, 'message' => 'Use an https:// or git@ repository address.'];
        }
        $process = new Process(['git', 'ls-remote', '--heads', $repository, $branch], null, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(15);
        $process->run();
        $line = trim($process->getOutput());

        return $process->isSuccessful() && $line !== ''
            ? ['ok' => true, 'message' => 'Repository reachable.', 'commit' => substr($line, 0, 40)]
            : ['ok' => false, 'message' => "We couldn't read branch {$branch} from {$repository}."];
    }

    /** @return array{0: string, 1: string, 2: string} registry, repository, tag */
    public static function parse(string $reference): array
    {
        $tag = 'latest';
        if (str_contains($reference, '@')) {
            [$reference, $tag] = explode('@', $reference, 2);
        } elseif (preg_match('/^(.*):([\w][\w.-]{0,127})$/', $reference, $m) && ! str_contains($m[2], '/')) {
            [$reference, $tag] = [$m[1], $m[2]];
        }
        $parts = explode('/', $reference);
        if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost')) {
            $registry = array_shift($parts);
        } else {
            $registry = 'registry-1.docker.io';
            if (count($parts) === 1) {
                array_unshift($parts, 'library');
            }
        }

        return [$registry, implode('/', $parts), $tag];
    }
}
