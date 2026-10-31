<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Alert;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\UptimeCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Public status page (monitoring): named components backed by the uptime checks of chosen
 * domains. Only component names, states, daily uptime and incident times are published;
 * domain names, alert titles and messages stay private.
 */
class StatusPageService
{
    public const DAYS = 90;

    public const INCIDENT_DAYS = 14;

    private const DEFAULTS = ['enabled' => false, 'title' => 'Service status', 'notice' => null, 'components' => []];

    public function __construct(private AuditLogger $audit) {}

    public function settings(): array
    {
        return array_replace(self::DEFAULTS, data_get($this->platform()?->settings, 'status_page', []));
    }

    public function save(array $input, ?string $userId): array
    {
        $org = $this->platform() ?? throw ApiException::conflict('No organisation exists yet.');
        $s = $this->settings();
        foreach (['enabled', 'title', 'notice'] as $k) {
            if (array_key_exists($k, $input)) {
                $s[$k] = $input[$k];
            }
        }
        if (array_key_exists('components', $input)) {
            $ids = array_column($input['components'], 'domain_id');
            $known = Domain::query()->whereIn('id', $ids)->pluck('id')->all();
            if (count($ids) !== count(array_unique($ids)) || count(array_unique($ids)) !== count($known)) {
                throw ApiException::validation(['components' => ['Each component needs a different, known domain.']]);
            }
            $s['components'] = array_map(fn ($c) => ['name' => $c['name'], 'domain_id' => $c['domain_id']], $input['components']);
        }
        $settings = $org->settings ?? [];
        $settings['status_page'] = $s;
        $org->update(['settings' => $settings]);
        Cache::forget('status-page');
        $this->audit->log('status_page.update', 'organizations', $org->id, ['enabled' => $s['enabled'], 'components' => count($s['components'])], null, $userId);

        return $s;
    }

    /** The published page, cached for a minute; null when the page is switched off. */
    public function public(): ?array
    {
        $s = $this->settings();
        if (! $s['enabled']) {
            return null;
        }

        return Cache::remember('status-page', 60, fn () => $this->build($s));
    }

    private function build(array $s): array
    {
        $since = now()->startOfDay()->subDays(self::DAYS - 1);
        $components = [];
        $incidents = [];
        foreach ($s['components'] as $c) {
            $domain = Domain::allTenants()->find($c['domain_id']);
            if (! $domain) {
                continue;
            }
            $daily = UptimeCheck::allTenants()->where('domain_id', $domain->id)->where('checked_at', '>=', $since)->get(['ok', 'checked_at'])
                ->groupBy(fn ($u) => Carbon::parse($u->checked_at)->toDateString());
            $days = [];
            $up = $total = 0;
            for ($d = $since->copy(); $d->lte(now()); $d->addDay()) {
                $rows = $daily[$d->toDateString()] ?? collect();
                $ok = $rows->where('ok', true)->count();
                $days[] = ['date' => $d->toDateString(), 'uptime_pct' => $rows->count() ? round($ok / $rows->count() * 100, 2) : null];
                $up += $ok;
                $total += $rows->count();
            }
            $open = Alert::allTenants()->where('resource_type', 'domain')->where('resource_id', $domain->id)->where('status', 'open')->exists();
            $state = match (true) {
                $this->inMaintenance($domain) => 'maintenance',
                $open => 'down',
                default => 'operational',
            };
            $components[] = ['name' => $c['name'], 'state' => $state, 'uptime_pct' => $total ? round($up / $total * 100, 3) : null, 'days' => $days];
            foreach (Alert::allTenants()->where('resource_type', 'domain')->where('resource_id', $domain->id)->where('created_at', '>=', now()->subDays(self::INCIDENT_DAYS))->orderByDesc('created_at')->limit(20)->get() as $a) {
                $incidents[] = ['component' => $c['name'], 'state' => $a->status === 'open' ? 'ongoing' : 'resolved',
                    'started_at' => $a->created_at?->toIso8601ZuluString(), 'resolved_at' => $a->resolved_at?->toIso8601ZuluString()];
            }
        }
        usort($incidents, fn ($a, $b) => strcmp($b['started_at'] ?? '', $a['started_at'] ?? ''));
        $states = array_column($components, 'state');

        return [
            'title' => $s['title'], 'notice' => $s['notice'] ?: null,
            'overall' => in_array('down', $states, true) ? 'down' : (in_array('maintenance', $states, true) ? 'maintenance' : 'operational'),
            'components' => $components, 'incidents' => array_slice($incidents, 0, 30), 'updated_at' => now()->toIso8601ZuluString(),
        ];
    }

    private function inMaintenance(Domain $domain): bool
    {
        return app(UptimeService::class)->inMaintenance($domain->organization_id, 'domain', $domain->id);
    }

    private function platform(): ?Organization
    {
        return Organization::query()->orderBy('created_at')->first();
    }
}
