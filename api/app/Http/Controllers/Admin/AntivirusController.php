<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Threat;
use App\Models\Volume;
use App\Services\AntivirusService;
use App\Support\Lists;
use Illuminate\Http\Request;

/** Antivirus (SRS 4.25): the threat list, scans and per-threat actions. */
class AntivirusController extends Controller
{
    public function __construct(private AntivirusService $antivirus) {}

    public function summary()
    {
        $counts = Threat::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return $this->data([
            'open' => (int) ($counts['infected'] ?? 0),
            'quarantined' => (int) ($counts['quarantined'] ?? 0),
            'resolved' => (int) (($counts['restored'] ?? 0) + ($counts['deleted'] ?? 0) + ($counts['ignored'] ?? 0)),
            'last_detected_at' => Threat::max('detected_at'),
            'auto_quarantine' => (bool) config('platform.antivirus.auto_quarantine'),
            'schedule' => AntivirusService::settings(\App\Support\Tenant::current()?->id),
        ]);
    }

    public function index(Request $request)
    {
        return response()->json(Lists::paginate(Threat::query()->with('server:id,name')->orderByDesc('detected_at'), $request,
            ['path', 'signature', 'volume_name'], ['detected_at', 'status'], ['status', 'server_id', 'volume_id'],
            fn (Threat $t) => [
                'id' => $t->id, 'path' => $t->path, 'signature' => $t->signature, 'sha256' => $t->sha256, 'size' => $t->size,
                'status' => $t->status, 'volume' => $t->volume_name, 'volume_id' => $t->volume_id, 'server' => $t->server?->name,
                'detected_at' => $t->detected_at?->toIso8601ZuluString(), 'resolved_at' => $t->resolved_at?->toIso8601ZuluString(),
            ]));
    }

    /** Scan one volume, or every volume (fleet). */
    public function scan(Request $request)
    {
        $data = $request->validate(['volume_id' => 'nullable|uuid']);
        if (! empty($data['volume_id'])) {
            return $this->accepted($this->antivirus->scan(Volume::findOrFail($data['volume_id']), $request->user()->id));
        }
        abort_unless($this->seesAllTenants($request), 403, 'Only platform administrators can scan the whole fleet.');

        return $this->data(['queued' => $this->antivirus->scanAll()], 202);
    }

    /** AV-10: each volume's baseline and the open changes found since. */
    public function integrity()
    {
        $open = \App\Models\IntegrityChange::where('status', 'open')->get();

        return $this->data([
            'volumes' => Volume::query()->where('type', '!=', 'nfs')->orderBy('name')->get()->map(fn (Volume $v) => [
                'id' => $v->id, 'name' => $v->name, 'baseline_at' => $v->integrity_baseline_at?->toIso8601ZuluString(),
                'checked_at' => $v->integrity_checked_at?->toIso8601ZuluString(), 'open' => $open->where('volume_id', $v->id)->count(),
            ])->values(),
            'changes' => $open->sortByDesc('detected_at')->take(500)->map(fn ($c) => [
                'id' => $c->id, 'volume_id' => $c->volume_id, 'volume' => $c->volume_name, 'path' => $c->path, 'change' => $c->change,
                'detected_at' => $c->detected_at->toIso8601ZuluString(),
            ])->values(),
        ]);
    }

    public function integrityAction(Request $request, string $id, string $action)
    {
        $volume = Volume::findOrFail($id);
        $service = app(\App\Services\IntegrityService::class);
        $job = match ($action) {
            'baseline' => $service->baseline($volume, $request->user()->id),
            'check' => $service->check($volume, $request->user()->id),
            'accept' => $service->accept($volume, $request->user()->id),
            default => abort(404),
        };

        return $this->accepted($job);
    }

    /** AV-2 / AV-12: real-time scanning and outbound limits of one server. Platform staff only. */
    public function serverSecurity(Request $request, string $id)
    {
        abort_unless($this->seesAllTenants($request), 403, 'Only platform administrators can change server security.');
        $server = \App\Models\Server::findOrFail($id);
        if ($request->isMethod('get')) {
            return $this->data(\App\Services\ServerSecurityService::settings($server));
        }
        $data = $request->validate([
            'realtime' => 'sometimes|boolean',
            'egress' => 'sometimes|array', 'egress.enabled' => 'sometimes|boolean', 'egress.block_mining' => 'sometimes|boolean',
            'egress.smtp_per_minute' => 'sometimes|integer|min:0|max:10000',
        ]);
        $request->attributes->set('audit_skip', true);

        return $this->data(app(\App\Services\ServerSecurityService::class)->update($server, $data));
    }

    /** AV-13 */
    public function score()
    {
        return $this->data($this->antivirus->score());
    }

    public function action(Request $request, string $id, string $action)
    {
        abort_unless(in_array($action, ['quarantine', 'restore', 'delete', 'ignore', 'clean'], true), 404);
        $job = $this->antivirus->act(Threat::findOrFail($id), $action, $request->user()->id);
        $request->attributes->set('audit_skip', true);

        return $job ? $this->accepted($job) : $this->data(['status' => 'ignored']);
    }
}
