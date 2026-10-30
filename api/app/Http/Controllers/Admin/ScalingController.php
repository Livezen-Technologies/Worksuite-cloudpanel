<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ScalingEvent;
use App\Services\ScalingService;
use Illuminate\Http\Request;

/**
 * SCL-1..5, 9: copies of an app, how they scale, health checks and sticky sessions.
 * Customer\ScalingController narrows this to the customer's apps and plan (SCL-10, USCL).
 */
class ScalingController extends Controller
{
    public function __construct(protected ScalingService $scaling) {}

    protected function app(string $id): Application
    {
        return Application::findOrFail($id);
    }

    /** @return array{max_replicas: int, autoscale: bool, schedule: bool} */
    protected function limits(): array
    {
        return ['max_replicas' => ScalingService::MAX, 'autoscale' => true, 'schedule' => true];
    }

    public function show(string $id)
    {
        $app = $this->app($id);

        return $this->data($this->present($app));
    }

    public function update(Request $request, string $id)
    {
        $app = $this->app($id);
        $lim = $this->limits();
        $data = $request->validate([
            'replicas' => 'sometimes|integer|min:1|max:'.ScalingService::MAX,
            'mode' => 'sometimes|in:'.implode(',', ScalingService::MODES),
            'min' => 'sometimes|integer|min:1|max:'.ScalingService::MAX,
            'max' => 'sometimes|integer|min:1|max:'.ScalingService::MAX,
            'cpu_target' => 'sometimes|integer|min:10|max:95',
            'schedule' => 'sometimes|array|max:10',
            'schedule.*.days' => 'sometimes|array', 'schedule.*.days.*' => 'integer|min:1|max:7',
            'schedule.*.from' => ['required_with:schedule', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'schedule.*.to' => ['required_with:schedule', 'regex:/^([01]\d|2[0-4]):[0-5]\d$/'],
            'schedule.*.replicas' => 'required_with:schedule|integer|min:1|max:'.ScalingService::MAX,
            'sticky' => 'sometimes|boolean',
            'health.path' => ['sometimes', 'string', 'max:200', 'regex:/^\//'],
            'health.interval' => 'sometimes|integer|min:2|max:300',
            'health.timeout' => 'sometimes|integer|min:1|max:60',
            'health.threshold' => 'sometimes|integer|min:1|max:10',
        ]);
        $s = array_replace_recursive(ScalingService::settings($app), array_intersect_key($data, array_flip(['mode', 'min', 'max', 'cpu_target', 'schedule', 'sticky', 'health'])));
        if (isset($data['schedule'])) {
            $s['schedule'] = $data['schedule'];
        }
        if ($s['mode'] === 'autoscale' && ! $lim['autoscale']) {
            throw ApiException::quota('Autoscaling is not included in your plan.');
        }
        if ($s['mode'] === 'scheduled' && ! $lim['schedule']) {
            throw ApiException::validation(['mode' => ['Scheduled scaling is not available here.']]);
        }
        $s['max'] = min((int) $s['max'], $lim['max_replicas']);
        if ($s['min'] > $s['max']) {
            throw ApiException::validation(['min' => ['The minimum cannot be above the maximum.']]);
        }
        $app->forceFill(['scaling' => $s])->save();
        if (isset($data['replicas'])) {
            if ($data['replicas'] > $lim['max_replicas']) {
                throw ApiException::quota("Your plan allows up to {$lim['max_replicas']} copies of an app.");
            }
            $this->scaling->scale($app, $data['replicas'], 'manual', $request->user()->id, $lim['max_replicas']);
        }
        $request->attributes->set('audit_skip', true);

        return $this->data($this->present($app->fresh()));
    }

    protected function present(Application $app): array
    {
        $containers = $this->scaling->containers($app);

        return [
            'replicas' => (int) ($app->replicas ?: 1),
            'settings' => ScalingService::settings($app),
            'limits' => $this->limits(),
            'copies' => $containers->map(fn ($c) => [
                'name' => $c->name, 'state' => $c->state, 'health' => $c->health, 'cpu_pct' => $c->cpu_pct, 'mem_mb' => $c->mem_mb,
            ])->values(),
            'healthy' => $containers->filter(fn ($c) => $c->state === 'running' && in_array($c->health, [null, '', 'healthy'], true))->count(),
            'warnings' => $this->scaling->warnings($app),
            'events' => ScalingEvent::allTenants()->where('application_id', $app->id)->orderByDesc('id')->limit(30)->get()
                ->map(fn ($e) => ['from' => $e->from, 'to' => $e->to, 'reason' => $e->reason, 'result' => $e->result, 'at' => $e->created_at?->toIso8601ZuluString()]),
            'notes' => ['Health-check and sticky-session changes apply at the next deploy.', 'Copies run on the app\'s server; spreading them over several servers is not available yet.'],
        ];
    }
}
