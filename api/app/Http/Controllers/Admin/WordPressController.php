<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Server;
use App\Models\WpSite;
use App\Services\WordPressService;
use Illuminate\Http\Request;

/** WordPress toolkit (SRS WP-001..004, 007, 008, 012). */
class WordPressController extends Controller
{
    public function __construct(private WordPressService $wp) {}

    /** WP-001 / WP-002: the sites and the summary over them. */
    public function index()
    {
        $sites = WpSite::query()->orderBy('url')->get();
        $apps = Application::allTenants()->whereIn('id', $sites->pluck('application_id'))->get()->keyBy('id');
        $servers = Server::allTenants()->whereIn('id', $sites->pluck('server_id'))->pluck('name', 'id');

        return $this->data([
            'summary' => [
                'sites' => $sites->count(), 'updates' => $sites->where('updates_count', '>', 0)->count(),
                'security' => $sites->where('security_count', '>', 0)->count(),
                'outdated_php' => $sites->filter(fn ($s) => $s->php_version && version_compare($s->php_version, '8.1', '<'))->count(),
                'staging' => $sites->where('staging', true)->count(),
            ],
            'sites' => $sites->map(fn (WpSite $s) => $this->present($s, $apps[$s->application_id] ?? null, $servers[$s->server_id] ?? null))->values(),
        ]);
    }

    public function show(string $id)
    {
        $s = WpSite::findOrFail($id);

        return $this->data($this->present($s, Application::allTenants()->find($s->application_id), Server::allTenants()->find($s->server_id)?->name) + [
            'plugins' => $s->plugins ?? [], 'themes' => $s->themes ?? [], 'vulnerabilities' => $s->vulnerabilities ?? [],
        ]);
    }

    public function detect()
    {
        return $this->data(['queued' => $this->wp->detect()], 202);
    }

    public function refresh(Request $request, string $id)
    {
        return $this->accepted($this->wp->inventory(WpSite::findOrFail($id), $request->user()->id));
    }

    public function setting(Request $request, string $id)
    {
        $data = $request->validate(['setting' => 'required|in:'.implode(',', WordPressService::SETTINGS), 'on' => 'required|boolean']);
        $request->attributes->set('audit_skip', true);

        return $this->accepted($this->wp->setting(WpSite::findOrFail($id), $data['setting'], (bool) $data['on'], $request->user()->id));
    }

    public function tool(Request $request, string $id, string $tool)
    {
        $data = $request->validate([
            'search' => 'nullable|string|min:3|max:500', 'replace' => 'nullable|string|max:500',
            'dry_run' => 'sometimes|boolean', 'plugin' => 'nullable|string|max:100', 'theme' => 'nullable|string|max:100',
        ]);
        if ($tool === 'search_replace' && empty($data['search'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['search' => 'Enter the text to search for (at least 3 characters).']);
        }
        $request->attributes->set('audit_skip', true);

        return $this->accepted($this->wp->tool(WpSite::findOrFail($id), $tool, $data + ['replace' => $data['replace'] ?? ''], $request->user()->id));
    }

    public function resetPassword(Request $request, string $id)
    {
        $data = $request->validate(['user' => 'required|string|max:60']);
        [$job, $password] = $this->wp->resetPassword(WpSite::findOrFail($id), $data['user'], $request->user()->id);
        $request->attributes->set('audit_skip', true);

        return $this->accepted($job, ['password' => $password]);
    }

    /** WP-012 */
    public function loginLink(Request $request, string $id)
    {
        [$job, $url] = $this->wp->loginLink(WpSite::findOrFail($id), $request->user()->id);
        $request->attributes->set('audit_skip', true);

        return $this->accepted($job, ['url' => $url, 'expires_in' => 60]);
    }

    private function present(WpSite $s, ?Application $app, ?string $server): array
    {
        return [
            'id' => $s->id, 'url' => $s->url, 'app' => $app?->name, 'application_id' => $s->application_id, 'server' => $server,
            'version' => $s->version, 'php_version' => $s->php_version, 'core_update' => $s->core_update,
            'updates' => $s->updates_count, 'security' => $s->security_count, 'score' => $s->score, 'staging' => $s->staging,
            'settings' => array_replace(array_fill_keys(WordPressService::SETTINGS, false), $s->settings ?? []),
            'error' => $s->inventory_error, 'inventoried_at' => $s->inventoried_at?->toIso8601ZuluString(),
        ];
    }
}
