<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Secret;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * ENV: comments a preview's addresses on its pull / merge request (GitHub, Gitea or
 * GitLab), with the project's Git token. Failures are reported, never thrown: the
 * preview itself has already started.
 */
class PreviewCommentService
{
    /** The provider API call for this webhook, or null when it can't be worked out. */
    public static function target(Request $request, int $number): ?array
    {
        if ($request->header('X-Gitlab-Event')) {
            $id = (int) $request->input('project.id');
            $base = self::origin((string) $request->input('project.web_url'));

            return $id && $base ? ['provider' => 'gitlab', 'url' => "{$base}/api/v4/projects/{$id}/merge_requests/{$number}/notes"] : null;
        }
        $full = (string) $request->input('repository.full_name');
        if (! preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $full)) {
            return null;
        }
        if ($request->header('X-Gitea-Event')) {
            $base = self::origin((string) $request->input('repository.html_url'));

            return $base ? ['provider' => 'gitea', 'url' => "{$base}/api/v1/repos/{$full}/issues/{$number}/comments"] : null;
        }
        $html = self::origin((string) $request->input('repository.html_url'));
        // github.com, or GitHub Enterprise's /api/v3 on its own host.
        $api = ! $html || $html === 'https://github.com' ? 'https://api.github.com' : $html.'/api/v3';

        return ['provider' => 'github', 'url' => "{$api}/repos/{$full}/issues/{$number}/comments"];
    }

    private static function origin(string $url): ?string
    {
        $p = parse_url($url);

        return isset($p['scheme'], $p['host']) && in_array($p['scheme'], ['https', 'http'], true)
            ? $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '') : null;
    }

    public static function body(int $number, array $urls): string
    {
        $lines = array_map(fn ($u) => "- {$u}", $urls);

        return "**Preview for #{$number} is deploying**\n\n".implode("\n", $lines)."\n\nIt updates on every push and is removed when the pull request closes.";
    }

    /** @return bool whether the comment was posted */
    public function post(Project $project, ?array $target, int $number, array $urls): bool
    {
        if (! $target || ! $urls || ! $project->preview_comment_secret_id) {
            return false;
        }
        $token = (string) Secret::find($project->preview_comment_secret_id)?->value;
        if ($token === '') {
            return false;
        }
        $req = Http::timeout(10)->acceptJson();
        $req = match ($target['provider']) {
            'gitlab' => $req->withHeaders(['PRIVATE-TOKEN' => $token]),
            'gitea' => $req->withHeaders(['Authorization' => 'token '.$token]),
            default => $req->withToken($token)->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']),
        };
        try {
            return $req->post($target['url'], ['body' => self::body($number, $urls)])->successful();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
