<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\SystemServiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** SVC-1..5: a server's systemd services, health, set-up and custom services. */
class SystemServiceController extends Controller
{
    public function __construct(private SystemServiceService $svc) {}

    public function show(string $id)
    {
        return $this->data($this->svc->present(Server::findOrFail($id)));
    }

    public function refresh(Request $request, string $id)
    {
        return $this->accepted($this->svc->refresh(Server::findOrFail($id), $request->user()->id));
    }

    public function action(Request $request, string $id)
    {
        $v = $request->validate(['unit' => 'required|string|max:128', 'action' => ['required', Rule::in(SystemServiceService::ACTIONS)]]);

        return $this->accepted($this->svc->action(Server::findOrFail($id), $v['unit'], $v['action'], $request->user()->id));
    }

    public function install(Request $request, string $id)
    {
        $v = $request->validate(['key' => ['required', Rule::in(array_keys(SystemServiceService::CATALOG))]]);

        return $this->accepted($this->svc->install(Server::findOrFail($id), $v['key'], $request->user()->id));
    }

    public function store(Request $request, string $id)
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,40}$/'],
            'description' => 'nullable|string|max:120',
            'exec_start' => ['required', 'string', 'max:500', 'regex:/^\/[^\r\n]*$/'],
            'working_dir' => ['nullable', 'string', 'max:255', 'regex:/^\/[^\r\n]*$/'],
            'user' => ['nullable', 'string', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/', 'not_in:root'],
            'restart' => ['nullable', Rule::in(['always', 'on-failure', 'no'])],
            'env' => 'nullable|array|max:50',
            'env.*' => ['nullable', 'string', 'max:500', 'regex:/^[^\r\n"\\\\]*$/'],
            'start' => 'boolean',
            'watch' => 'boolean',
        ], ['exec_start.regex' => 'Start with an absolute path, e.g. /usr/bin/node server.js.']);
        foreach (array_keys($v['env'] ?? []) as $k) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', (string) $k)) {
                throw \App\Exceptions\ApiException::validation(['env' => ["Invalid variable name {$k}."]]);
            }
        }
        $server = Server::findOrFail($id);
        if ($request->boolean('watch')) {
            $server->update(['services_watch' => array_values(array_unique([...($server->services_watch ?? []), "ws-{$v['name']}.service"]))]);
        }
        unset($v['watch']);

        return $this->accepted($this->svc->create($server, $v + ['start' => true], $request->user()->id));
    }

    public function destroy(Request $request, string $id)
    {
        $v = $request->validate(['unit' => 'required|string|max:128']);

        return $this->accepted($this->svc->delete(Server::findOrFail($id), $v['unit'], $request->user()->id));
    }

    public function logs(Request $request, string $id)
    {
        $v = $request->validate(['unit' => 'required|string|max:128', 'lines' => 'nullable|integer|between:10,1000']);

        return $this->accepted($this->svc->logs(Server::findOrFail($id), $v['unit'], (int) ($v['lines'] ?? 200), $request->user()->id));
    }

    public function watch(Request $request, string $id)
    {
        $v = $request->validate(['units' => 'present|array|max:100', 'units.*' => 'string|max:128']);
        $server = $this->svc->watch(Server::findOrFail($id), $v['units']);

        return $this->data($this->svc->present($server));
    }
}
