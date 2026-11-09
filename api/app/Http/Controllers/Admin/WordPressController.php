<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Server;
use App\Models\WpRestorePoint;
use App\Models\WpSite;
use App\Services\WordPressService;
use Illuminate\Http\Request;

/** WordPress toolkit (SRS WP-001..012). */
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
            'staging_password' => $s->production_id ? $s->staging_password : null,
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

    /** WP-005 */
    public function update(Request $request, string $id)
    {
        $data = $request->validate(['scope' => 'required|in:core,plugins,themes,all', 'items' => 'sometimes|array|max:200', 'items.*' => 'string|max:100']);
        $request->attributes->set('audit_skip', true);

        return $this->accepted($this->wp->update(WpSite::findOrFail($id), $data['scope'], $data['items'] ?? [], $request->user()->id));
    }

    /** WP-006 */
    public function updateAll(Request $request)
    {
        $data = $request->validate(['scope' => 'required|in:core,plugins,themes,all']);
        $request->attributes->set('audit_skip', true);
        $jobs = $this->wp->updateAll($data['scope'], $request->user()->id);

        return $this->data(['queued' => count($jobs), 'job_ids' => $jobs], 202);
    }

    /** WP-009 */
    public function hardening(Request $request, string $id)
    {
        $rules = collect(WordPressService::HARDENING)->mapWithKeys(fn ($k) => [$k => 'sometimes|boolean'])->all();
        $data = $request->validate($rules);
        $request->attributes->set('audit_skip', true);

        return $this->accepted($this->wp->harden(WpSite::findOrFail($id), $data, $request->user()->id));
    }

    /** WP-011 */
    public function restorePoints(string $id)
    {
        $site = WpSite::findOrFail($id);

        return $this->data(WpRestorePoint::query()->where('wp_site_id', $site->id)->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (WpRestorePoint $p) => [
                'id' => $p->id, 'reason' => $p->reason, 'status' => $p->status, 'version' => $p->version,
                'plugins' => count($p->plugins ?? []), 'themes' => count($p->themes ?? []),
                'created_at' => $p->created_at?->toIso8601ZuluString(), 'restored_at' => $p->restored_at?->toIso8601ZuluString(),
            ]));
    }

    public function createRestorePoint(Request $request, string $id)
    {
        $request->attributes->set('audit_skip', true);

        return $this->accepted($this->wp->createRestorePoint(WpSite::findOrFail($id), $request->user()->id));
    }

    public function restore(Request $request, string $id, string $point)
    {
        $site = WpSite::findOrFail($id);
        $request->attributes->set('audit_skip', true);

        return $this->accepted($this->wp->restore($site, WpRestorePoint::query()->where('wp_site_id', $site->id)->findOrFail($point), $request->user()->id));
    }

    /** WP-010: create (from the live site) or act on a staging site. */
    public function createStaging(Request $request, string $id)
    {
        $request->attributes->set('audit_skip', true);
        $staging = $this->wp->createStaging(WpSite::findOrFail($id), $request->user()->id);

        return $this->data(['staging_id' => $staging->id, 'url' => $staging->url, 'user' => 'staging', 'password' => $staging->staging_password], 202);
    }

    public function stagingAction(Request $request, string $id, string $action)
    {
        $staging = WpSite::findOrFail($id);
        $request->attributes->set('audit_skip', true);

        return $this->accepted(match ($action) {
            'refresh' => $this->wp->refreshStaging($staging, $request->user()->id),
            'push' => $this->wp->pushStaging($staging, $request->user()->id),
            'delete' => $this->wp->deleteStaging($staging, $request->user()->id),
        });
    }

    private function present(WpSite $s, ?Application $app, ?string $server): array
    {
        return [
            'id' => $s->id, 'url' => $s->url, 'app' => $app?->name, 'application_id' => $s->application_id, 'server' => $server,
            'version' => $s->version, 'php_version' => $s->php_version, 'core_update' => $s->core_update,
            'updates' => $s->updates_count, 'security' => $s->security_count, 'score' => $s->score, 'staging' => $s->staging,
            'settings' => array_replace(array_fill_keys(WordPressService::SETTINGS, false), array_diff_key($s->settings ?? [], ['hardening' => 1])),
            'hardening' => array_replace(array_fill_keys(WordPressService::HARDENING, false), $s->settings['hardening'] ?? []),
            'production_id' => $s->production_id, 'staging_status' => $s->staging_status,
            'staging_id' => $s->production_id ? null : WpSite::query()->where('production_id', $s->id)->value('id'),
            'error' => $s->inventory_error, 'inventoried_at' => $s->inventoried_at?->toIso8601ZuluString(),
        ];
    }
}
