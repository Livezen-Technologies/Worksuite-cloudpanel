<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Job;
use App\Models\Threat;
use App\Models\Volume;
use App\Services\AntivirusService;
use Illuminate\Http\Request;

/**
 * USEC-1..3, 5: the customer's own security page. Everything is limited to the volumes of
 * their own apps: other customers' threats, server-wide bans and other images never show.
 */
class SecurityController extends Controller
{
    public function __construct(private AntivirusService $av) {}

    public function index()
    {
        $volumes = $this->volumes();
        $names = $volumes->pluck('name', 'id');
        $threats = Threat::allTenants()->whereIn('volume_id', $volumes->pluck('id'))->latest('detected_at')->limit(200)->get();
        $score = $this->av->score();
        $sites = collect($score['volumes'])->whereIn('volume_id', $volumes->pluck('id')->all())->values();

        return $this->data([
            'score' => $sites->isEmpty() ? $score['score'] : (int) round($sites->avg('score')),
            'sites' => $sites->map(fn ($s) => ['site' => $this->siteOf($s['volume']), 'score' => $s['score'], 'fixes' => $s['fixes'], 'last_scan_at' => $s['last_scan_at']]),
            'open_threats' => $threats->where('status', 'infected')->count(),
            'quarantined' => $threats->where('status', 'quarantined')->count(),
            'scans_30d' => Job::allTenants()->where('type', 'av.scan')->whereIn('resource_id', $volumes->pluck('id'))->where('status', 'succeeded')
                ->where('finished_at', '>=', now()->subDays(30))->count(),
            'scanning' => $this->scanning($volumes),
            'threats' => $threats->map(fn (Threat $t) => [
                'id' => $t->id, 'site' => $this->siteOf($names[$t->volume_id] ?? $t->volume_name), 'path' => $t->path, 'signature' => $t->signature,
                'status' => $t->status, 'detected_at' => $t->detected_at?->toIso8601ZuluString(),
            ])->values(),
        ]);
    }

    /** USEC-3: all of the customer's sites, one running scan per customer. */
    public function scan(Request $request)
    {
        $volumes = $this->volumes();
        if ($volumes->isEmpty()) {
            throw ApiException::conflict('You have no sites to scan yet.');
        }
        if ($this->scanning($volumes)) {
            throw ApiException::conflict('A scan is already running.');
        }
        $jobs = $volumes->map(fn (Volume $v) => $this->av->scan($v, $request->user()->id)->id);

        return $this->data(['queued' => $jobs->count(), 'job_ids' => $jobs->values()], 202);
    }

    /** USEC-2: quarantine, or mark as not a threat. */
    public function act(Request $request, string $id, string $action)
    {
        $threat = Threat::allTenants()->whereIn('volume_id', $this->volumes()->pluck('id'))->findOrFail($id);
        $job = $this->av->act($threat, $action, $request->user()->id);
        $request->attributes->set('audit_skip', true);

        return $job ? $this->accepted($job) : $this->data(['status' => $threat->fresh()->status]);
    }

    /** The data volumes of the customer's own apps. */
    private function volumes()
    {
        $names = Application::where('kind', 'customer')->pluck('name')->map(fn ($n) => 'app-'.$n.'-data');

        return Volume::allTenants()->whereIn('name', $names)->where('type', '!=', 'nfs')->get();
    }

    private function scanning($volumes): bool
    {
        return Job::allTenants()->where('type', 'av.scan')->whereIn('resource_id', $volumes->pluck('id'))->whereIn('status', ['queued', 'running'])->exists();
    }

    private function siteOf(string $volume): string
    {
        return (string) preg_replace('/^app-(.+)-data$/', '$1', $volume);
    }
}
