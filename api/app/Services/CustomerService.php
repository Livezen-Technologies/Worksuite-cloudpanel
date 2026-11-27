<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Contact;
use App\Models\CustomerActivity;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupMember;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Vps;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CUST-1..3: everything the customer directory and profile need to agree on --
 * what an account's status is, what it owns, which groups it is in, and what
 * has happened to it.
 *
 * Counts are gathered per page of customers in grouped queries, never one
 * query per row: the directory shows 100 accounts at a time and each one has
 * five things to count.
 */
class CustomerService
{
    public const STATUSES = ['active', 'pending', 'trial', 'suspended', 'closed'];

    public const TYPES = ['individual', 'business', 'enterprise', 'reseller'];

    /** The twelve groups the spec asks for, created on first use and undeletable. */
    public const DEFAULT_GROUPS = [
        ['individual', 'Individual', 'static'],
        ['business', 'Business', 'static'],
        ['enterprise', 'Enterprise', 'static'],
        ['reseller', 'Reseller', 'static'],
        ['vip', 'VIP', 'static'],
        ['trial', 'Trial', 'static'],
        ['shared-hosting', 'Shared Hosting', 'dynamic'],
        ['wordpress-hosting', 'WordPress Hosting', 'dynamic'],
        ['vps-customers', 'VPS Customers', 'dynamic'],
        ['dedicated-server-customers', 'Dedicated Server Customers', 'static'],
        ['domain-customers', 'Domain Customers', 'dynamic'],
        ['inactive-customers', 'Inactive Customers', 'dynamic'],
    ];

    /**
     * Counts for a page of accounts, keyed by organisation id.
     *
     * @param  list<string>  $orgIds
     * @return array<string, array<string, int>>
     */
    public function countsFor(array $orgIds): array
    {
        if ($orgIds === []) {
            return [];
        }

        $by = fn (string $table, callable|null $tweak = null) => tap(
            DB::table($table)->whereIn('organization_id', $orgIds)->groupBy('organization_id')
                ->selectRaw('organization_id, count(*) as total'),
            fn ($q) => $tweak && $tweak($q),
        )->pluck('total', 'organization_id');

        $apps = $by('applications', fn ($q) => $q->whereNull('deleted_at'));
        $vps = $by('vps');
        $domains = $by('domains');
        $users = $by('memberships');
        $groups = $by('customer_group_members');

        $out = [];
        foreach ($orgIds as $id) {
            $out[$id] = [
                'applications' => (int) ($apps[$id] ?? 0),
                'vps' => (int) ($vps[$id] ?? 0),
                'domains' => (int) ($domains[$id] ?? 0),
                'users' => (int) ($users[$id] ?? 0),
                'groups' => (int) ($groups[$id] ?? 0),
            ];
            $out[$id]['services'] = $out[$id]['applications'] + $out[$id]['vps'] + $out[$id]['domains'];
        }

        return $out;
    }

    /**
     * Group names for a page of accounts, keyed by organisation id.
     *
     * @param  list<string>  $orgIds
     * @return array<string, list<array{id: string, name: string, colour: string|null}>>
     */
    public function groupsFor(array $orgIds): array
    {
        if ($orgIds === []) {
            return [];
        }

        $rows = DB::table('customer_group_members as m')
            ->join('customer_groups as g', 'g.id', '=', 'm.customer_group_id')
            ->whereIn('m.organization_id', $orgIds)
            ->orderBy('g.name')
            ->get(['m.organization_id', 'g.id', 'g.name', 'g.colour']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->organization_id][] = ['id' => $r->id, 'name' => $r->name, 'colour' => $r->colour];
        }

        return $out;
    }

    /** One customer as the directory shows it. */
    public function shape(Organization $org, array $counts = [], array $groups = []): array
    {
        $owner = $org->memberships->firstWhere('role', 'owner') ?? $org->memberships->first();
        $sub = $org->subscription;

        return [
            'id' => $org->id,
            'name' => $org->name,
            'slug' => $org->slug,
            'email' => $owner?->user?->email ?? $org->billing_email,
            'phone' => $owner?->user?->phone,
            'country' => $owner?->user?->country ?? $org->billingProfile?->country,
            'customer_type' => $org->customer_type,
            'company' => $org->billingProfile?->company_name,
            'groups' => $groups,
            'account_manager' => $org->accountManager?->name,
            'account_manager_id' => $org->account_manager_id,
            'services' => $counts['services'] ?? 0,
            'applications' => $counts['applications'] ?? 0,
            'vps' => $counts['vps'] ?? 0,
            'domains' => $counts['domains'] ?? 0,
            'users' => $counts['users'] ?? 0,
            'plan' => $sub?->plan?->name,
            'subscription_status' => $sub?->status,
            'onboarding_status' => $org->onboarding_status,
            'status' => $org->status,
            'registered_at' => ($org->registered_at ?? $org->created_at)?->toIso8601String(),
            'last_activity_at' => $org->last_activity_at?->toIso8601String(),
            'archived_at' => $org->archived_at?->toIso8601String(),
        ];
    }

    /**
     * Move an account between lifecycle states, recording why.
     *
     * The old suspension path wrote `settings.suspension` and left status to be
     * guessed; the column is now the answer and the JSON keeps the reason, so
     * both stay in step.
     */
    public function setStatus(Organization $org, string $status, ?string $reason, ?string $actorId): Organization
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw \App\Exceptions\ApiException::validation(['status' => ['Unknown customer status.']]);
        }
        $from = $org->status;
        $settings = $org->settings ?? [];
        if ($status === 'suspended') {
            $settings['suspension'] = ['reason' => $reason, 'at' => now()->toIso8601ZuluString()];
        } elseif ($from === 'suspended') {
            unset($settings['suspension']);
        }
        $settings['closed'] = $status === 'closed' ? true : null;
        $org->forceFill(['status' => $status, 'settings' => array_filter($settings, fn ($v) => $v !== null)])->save();

        $this->record($org, 'status_changed', "Account moved from {$from} to {$status}.", [
            'from' => $from, 'to' => $status, 'reason' => $reason,
        ], $actorId);

        return $org;
    }

    /** Replace an account's manual group membership, leaving rule-driven rows alone. */
    public function setGroups(Organization $org, array $groupIds, ?string $actorId): void
    {
        DB::transaction(function () use ($org, $groupIds, $actorId) {
            CustomerGroupMember::allTenants()->where('organization_id', $org->id)
                ->where('source', 'manual')->whereNotIn('customer_group_id', $groupIds)->delete();

            $have = CustomerGroupMember::allTenants()->where('organization_id', $org->id)
                ->pluck('customer_group_id')->all();

            foreach (array_diff($groupIds, $have) as $id) {
                CustomerGroupMember::create([
                    'organization_id' => $org->id,
                    'customer_group_id' => $id,
                    'source' => 'manual',
                    'added_by' => $actorId,
                ]);
            }
        });

        $names = CustomerGroup::whereIn('id', $groupIds)->orderBy('name')->pluck('name')->all();
        $this->record($org, 'groups_changed', $names === []
            ? 'Removed from every group.'
            : 'Groups set to '.implode(', ', $names).'.', ['group_ids' => array_values($groupIds)], $actorId);
    }

    /**
     * The largest discount among an account's groups.
     *
     * Two groups can both carry one; taking the largest is a decision, not an
     * accident of iteration order, so it lives here where Finance can find it.
     */
    public function groupDiscountPercent(Organization $org): int
    {
        return (int) DB::table('customer_group_members as m')
            ->join('customer_groups as g', 'g.id', '=', 'm.customer_group_id')
            ->where('m.organization_id', $org->id)
            ->max('g.discount_percent');
    }

    /**
     * The pricing an account's groups give it: the largest discount, the group
     * that granted it, and any per-plan price overrides.
     *
     * Finance asks this one question rather than reading groups itself, so the
     * "largest discount wins" rule has exactly one home. Where two groups
     * override the same plan, the cheaper price wins -- the customer is never
     * worse off for being in an extra group.
     *
     * @return array{discount_percent:int, group_id:?string, group_name:?string, plan_overrides:array<string,int>}
     */
    public function pricingFor(Organization $org): array
    {
        $groups = DB::table('customer_group_members as m')
            ->join('customer_groups as g', 'g.id', '=', 'm.customer_group_id')
            ->where('m.organization_id', $org->id)
            ->select('g.id', 'g.name', 'g.discount_percent', 'g.plan_overrides')
            ->orderByDesc('g.discount_percent')->orderBy('g.name')->get();

        $overrides = [];
        foreach ($groups as $g) {
            foreach (json_decode($g->plan_overrides ?? '[]', true) ?: [] as $planId => $cents) {
                $cents = (int) $cents;
                if (! isset($overrides[$planId]) || $cents < $overrides[$planId]) {
                    $overrides[$planId] = $cents;
                }
            }
        }

        $best = $groups->first();

        return [
            'discount_percent' => (int) ($best->discount_percent ?? 0),
            'group_id' => ($best && $best->discount_percent > 0) ? $best->id : null,
            'group_name' => ($best && $best->discount_percent > 0) ? $best->name : null,
            'plan_overrides' => $overrides,
        ];
    }

    /** Append to the customer's timeline and move its last-activity stamp. */
    public function record(Organization $org, string $kind, string $summary, array $meta = [], ?string $actorId = null, ?string $userId = null): CustomerActivity
    {
        $at = now();
        $activity = CustomerActivity::create([
            'organization_id' => $org->id,
            'user_id' => $userId,
            'actor_id' => $actorId,
            'kind' => $kind,
            'summary' => $summary,
            'meta' => $meta === [] ? null : $meta,
            'occurred_at' => $at,
        ]);
        $org->forceFill(['last_activity_at' => $at])->saveQuietly();

        return $activity;
    }

    /** The figures on the Customers overview. */
    public function overview(CarbonInterface $from, CarbonInterface $to): array
    {
        $orgs = Organization::query();
        $count = fn (callable $f) => (int) $f(Organization::query())->count();

        $expiringSoon = Subscription::allTenants()
            ->whereIn('status', ['active', 'trialing'])
            ->whereBetween('current_period_end', [now(), now()->addDays(30)])
            ->distinct()->count('organization_id');

        $withApps = DB::table('applications')->whereNull('deleted_at')->distinct()->count('organization_id');
        $withVps = DB::table('vps')->distinct()->count('organization_id');

        $total = (clone $orgs)->count();
        $closedInWindow = $count(fn ($q) => $q->where('status', 'closed')->whereBetween('updated_at', [$from, $to]));
        $atStart = $count(fn ($q) => $q->where('created_at', '<', $from));

        return [
            'total_customers' => $total,
            'active_customers' => $count(fn ($q) => $q->where('status', 'active')),
            'suspended_customers' => $count(fn ($q) => $q->where('status', 'suspended')),
            'new_today' => $count(fn ($q) => $q->where('created_at', '>=', now()->startOfDay())),
            'new_this_month' => $count(fn ($q) => $q->where('created_at', '>=', now()->startOfMonth())),
            'new_in_range' => $count(fn ($q) => $q->whereBetween('created_at', [$from, $to])),
            'pending_verification' => $count(fn ($q) => $q->where('status', 'pending')),
            'onboarding_pending' => $count(fn ($q) => $q->where('onboarding_status', 'in_progress')),
            'onboarding_completed' => $count(fn ($q) => $q->where('onboarding_status', 'completed')),
            'onboarding_failed' => $count(fn ($q) => $q->where('onboarding_status', 'failed')),
            'total_companies' => (int) DB::table('billing_profiles')->whereNotNull('company_name')->count(),
            'total_groups' => (int) DB::table('customer_groups')->count(),
            'hosting_customers' => $withApps,
            'vps_customers' => $withVps,
            'expiring_services' => $expiringSoon,
            // Retention over the window: of those who existed when it opened,
            // how many are not closed now. Null rather than 100% when nobody did.
            'retention_rate' => $atStart === 0 ? null : round((($atStart - $closedInWindow) / $atStart) * 100, 2),
            'by_type' => $this->breakdown('customer_type'),
            'by_status' => $this->breakdown('status'),
            'growth' => $this->growth(),
        ];
    }

    /** @return list<array{label: string, total: int}> */
    private function breakdown(string $column): array
    {
        // A known column only, selected through the builder (which quotes it) rather than raw SQL.
        $column = match ($column) { 'customer_type', 'status' => $column };

        return DB::table('organizations')->groupBy($column)->select("{$column} as label")->selectRaw('count(*) as total')
            ->orderByDesc('total')->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'total' => (int) $r->total])->all();
    }

    /** New customers per month over the last twelve, oldest first. */
    private function growth(): array
    {
        $start = now()->copy()->startOfMonth()->subMonths(11);
        $rows = DB::table('organizations')->where('created_at', '>=', $start)->get(['created_at']);
        $buckets = [];
        for ($i = 0; $i < 12; $i++) {
            $buckets[$start->copy()->addMonths($i)->format('Y-m')] = 0;
        }
        foreach ($rows as $r) {
            $key = Carbon::parse($r->created_at)->format('Y-m');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        return array_map(fn ($m, $n) => ['month' => $m, 'customers' => $n], array_keys($buckets), $buckets);
    }

    /** Everything a profile header shows, without loading any one tab. */
    public function profile(Organization $org): array
    {
        $counts = $this->countsFor([$org->id])[$org->id] ?? [];
        $groups = $this->groupsFor([$org->id])[$org->id] ?? [];

        return $this->shape($org, $counts, $groups) + [
            'billing_profile' => $org->billingProfile,
            'contacts' => Contact::allTenants()->where('organization_id', $org->id)->orderBy('role')->get(),
            'group_discount_percent' => $this->groupDiscountPercent($org),
            'tabs' => [
                'invoices' => (int) Invoice::allTenants()->where('organization_id', $org->id)->count(),
                'applications' => $counts['applications'] ?? 0,
                'vps' => $counts['vps'] ?? 0,
                'domains' => $counts['domains'] ?? 0,
                'users' => $counts['users'] ?? 0,
            ],
            'suspension' => ($org->settings ?? [])['suspension'] ?? null,
        ];
    }

    /** The services tab, in one shape rather than four. */
    public function services(Organization $org): array
    {
        $rows = [];
        foreach (Application::allTenants()->whereNull('deleted_at')->where('organization_id', $org->id)->get() as $a) {
            $rows[] = ['kind' => 'application', 'id' => $a->id, 'name' => $a->name, 'status' => $a->status, 'created_at' => $a->created_at?->toIso8601String()];
        }
        foreach (Vps::allTenants()->where('organization_id', $org->id)->get() as $v) {
            $rows[] = ['kind' => 'vps', 'id' => $v->id, 'name' => $v->name, 'status' => $v->status, 'created_at' => $v->created_at?->toIso8601String()];
        }
        foreach (Domain::allTenants()->where('organization_id', $org->id)->get() as $d) {
            $rows[] = ['kind' => 'domain', 'id' => $d->id, 'name' => $d->name, 'status' => $d->https_status === 'active' ? 'active' : $d->dns_status, 'created_at' => $d->created_at?->toIso8601String()];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $rows;
    }
}
