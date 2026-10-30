<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CleanupRun;
use App\Models\Server;
use App\Services\CleanupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CLN-1..5: a server's cleanup policy, protected images, history and manual runs. */
class CleanupController extends Controller
{
    public function __construct(private CleanupService $cleanup) {}

    private function present(Server $server): array
    {
        return [
            'policy' => $this->cleanup->policy($server),
            'levels' => CleanupService::LEVELS,
            'disk_pct' => $server->disk_pct,
            'protected' => $this->cleanup->protectedImages($server),
            'runs' => CleanupRun::where('server_id', $server->id)->latest('id')->limit(20)->get(),
        ];
    }

    public function show(string $id)
    {
        return $this->data($this->present(Server::findOrFail($id)));
    }

    public function update(Request $request, string $id)
    {
        $server = Server::findOrFail($id);
        $v = $request->validate([
            'auto' => 'sometimes|boolean',
            'level' => ['sometimes', Rule::in(CleanupService::LEVELS)],
            'disk_threshold' => 'sometimes|integer|between:50,99',
            'keep_releases' => 'sometimes|integer|between:1,10',
        ]);
        $server->update(['cleanup' => array_merge($this->cleanup->policy($server), $v)]);

        return $this->data($this->present($server->fresh()));
    }

    public function run(Request $request, string $id)
    {
        $server = Server::findOrFail($id);
        $v = $request->validate(['level' => ['required', Rule::in(CleanupService::LEVELS)], 'dry_run' => 'sometimes|boolean']);
        $run = $this->cleanup->run($server, $v['level'], 'manual', $request->user(), (bool) ($v['dry_run'] ?? false));

        return $this->data($run, 202);
    }
}
