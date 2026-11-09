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

    public function git(string $repository, string $branch, ?string $sshPrivateKey = null): array
    {
        if (! preg_match('#^(https://|git@|ssh://[\w.-]+@)[\w.@:/~-]+$#', $repository)) {
            return ['ok' => false, 'message' => 'Use an https://, git@ or ssh:// repository address.'];
        }
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $branch)) {
            return ['ok' => false, 'message' => 'Invalid branch name.'];
        }
        $env = ['GIT_TERMINAL_PROMPT' => '0'];
        $dir = null;
        if ($sshPrivateKey !== null) {
            // A private temp dir for the key and the host keys seen on first contact.
            $dir = sys_get_temp_dir().'/gitkey-'.bin2hex(random_bytes(8));
            mkdir($dir, 0700);
            file_put_contents("{$dir}/id", rtrim($sshPrivateKey)."\n");
            chmod("{$dir}/id", 0600);
            $env['GIT_SSH_COMMAND'] = 'ssh -i '.escapeshellarg("{$dir}/id").' -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile='.escapeshellarg("{$dir}/known_hosts");
        }
        try {
            $process = new Process(['git', 'ls-remote', '--heads', $repository, $branch], null, $env);
            $process->setTimeout(20);
            $process->run();
        } finally {
            if ($dir !== null) {
                @unlink("{$dir}/id");
                @unlink("{$dir}/known_hosts");
                @rmdir($dir);
            }
        }
        $line = trim($process->getOutput());
        if ($process->isSuccessful() && $line !== '') {
            return ['ok' => true, 'message' => 'Repository reachable.', 'commit' => substr($line, 0, 40)];
        }
        if ($process->isSuccessful()) {
            return ['ok' => false, 'message' => "The repository has no branch {$branch}."];
        }
        $err = (string) $process->getErrorOutput();

        return ['ok' => false, 'message' => str_contains($err, 'Permission denied') || str_contains($err, 'publickey')
            ? 'The repository refused the deploy key. Add the public key to the repository (read access is enough) and try again.'
            : "We couldn't read branch {$branch} from {$repository}."];
    }

    public function defaultBranch(string $repository): ?string
    {
        if (! preg_match('#^(https://|git@)[\w.@:/~%-]+$#', $repository)) {
            return null;
        }
        $process = new Process(['git', 'ls-remote', '--symref', $repository, 'HEAD'], null, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful() && preg_match('#^ref:\s+refs/heads/(\S+)\s+HEAD#m', $process->getOutput(), $m) ? $m[1] : null;
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
