<?php

namespace App\Http\Controllers\Admin\Customers;

use App\Http\Controllers\Controller;
use App\Models\CustomerActivity;
use App\Models\CustomerGroup;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\CustomerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CUST-3: the customer directory and the unified profile.
 *
 * The old Hosting Customers list returned every organisation unpaginated and
 * derived status by guessing from the subscription; this reads the status
 * column and pages like every other list in the panel.
 */
class DirectoryController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    public function index(Request $request)
    {
        $result = \App\Support\Lists::paginate(
            $this->filtered($request),
            $request,
            ['name', 'slug', 'billing_email'],
            ['name', 'created_at', 'last_activity_at', 'status'],
            [],
            fn (Organization $o) => $o,          // shaped in bulk below, not row by row
        );

        $orgs = collect($result['data']);
        $ids = $orgs->pluck('id')->all();
        $counts = $this->customers->countsFor($ids);
        $groups = $this->customers->groupsFor($ids);
        $result['data'] = $orgs->map(fn (Organization $o) => $this->customers->shape($o, $counts[$o->id] ?? [], $groups[$o->id] ?? []))->all();

        return response()->json($result);
    }

    public function show(string $id)
    {
        return $this->data($this->customers->profile($this->find($id)));
    }

    public function update(Request $request, string $id)
    {
        $org = $this->find($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'],
            'billing_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'customer_type' => ['sometimes', Rule::in(CustomerService::TYPES)],
            'account_manager_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')],
        ]);
        $org->fill($data)->save();
        app(AuditLogger::class)->log('customer.updated', 'organization', $org->id, ['fields' => array_keys($data)]);
        $this->customers->record($org, 'profile_updated', 'Account details changed.', ['fields' => array_keys($data)], $request->user()?->id);

        return $this->data($this->customers->profile($org->fresh()));
    }

    /** Move the account between lifecycle states. Suspending needs a reason. */
    public function status(Request $request, string $id)
    {
        $org = $this->find($id);
        $data = $request->validate([
            'status' => ['required', Rule::in(CustomerService::STATUSES)],
            'reason' => ['required_if:status,suspended,closed', 'nullable', 'string', 'max:300'],
        ]);
        if ($org->status === 'closed' && $data['status'] !== 'closed') {
            throw \App\Exceptions\ApiException::conflict('This account is closed. Reopening it is not done from here.');
        }
        $this->customers->setStatus($org, $data['status'], $data['reason'] ?? null, $request->user()?->id);
        app(AuditLogger::class)->log('customer.status', 'organization', $org->id, $data);

        return $this->data($this->customers->profile($org->fresh()));
    }

    public function archive(Request $request, string $id)
    {
        $org = $this->find($id);
        $org->forceFill(['archived_at' => now()])->save();
        app(AuditLogger::class)->log('customer.archived', 'organization', $org->id);
        $this->customers->record($org, 'archived', 'Account archived.', [], $request->user()?->id);

        return $this->data($this->customers->profile($org->fresh()));
    }

    public function restore(Request $request, string $id)
    {
        $org = $this->find($id);
        $org->forceFill(['archived_at' => null])->save();
        app(AuditLogger::class)->log('customer.restored', 'organization', $org->id);
        $this->customers->record($org, 'restored', 'Account restored from the archive.', [], $request->user()?->id);

        return $this->data($this->customers->profile($org->fresh()));
    }

    public function setGroups(Request $request, string $id)
    {
        $org = $this->find($id);
        $data = $request->validate([
            'group_ids' => ['present', 'array'],
            'group_ids.*' => ['uuid', Rule::exists('customer_groups', 'id')],
        ]);
        $this->customers->setGroups($org, $data['group_ids'], $request->user()?->id);
        app(AuditLogger::class)->log('customer.groups', 'organization', $org->id, $data);

        return $this->data($this->customers->profile($org->fresh()));
    }

    public function activities(Request $request, string $id)
    {
        $org = $this->find($id);

        return response()->json(\App\Support\Lists::paginate(
            CustomerActivity::allTenants()->where('organization_id', $org->id)->orderByDesc('occurred_at'),
            $request,
            ['summary', 'kind'],
            ['occurred_at'],
            ['kind' => 'kind'],
        ));
    }

    public function services(string $id)
    {
        return $this->data($this->customers->services($this->find($id)));
    }

    /** The directory as CSV, honouring whatever the screen is filtered to. */
    public function export(Request $request): StreamedResponse
    {
        $orgs = $this->filtered($request)->get();
        $ids = $orgs->pluck('id')->all();
        $counts = $this->customers->countsFor($ids);
        $groups = $this->customers->groupsFor($ids);

        return response()->streamDownload(function () use ($orgs, $counts, $groups) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Customer', 'Email', 'Country', 'Type', 'Company', 'Groups', 'Account manager',
                'Services', 'Plan', 'Subscription', 'Onboarding', 'Status', 'Registered', 'Last activity']);
            foreach ($orgs as $o) {
                $r = $this->customers->shape($o, $counts[$o->id] ?? [], $groups[$o->id] ?? []);
                fputcsv($out, [
                    $r['name'], $r['email'], $r['country'], $r['customer_type'], $r['company'],
                    implode(' / ', array_column($r['groups'], 'name')), $r['account_manager'],
                    $r['services'], $r['plan'], $r['subscription_status'], $r['onboarding_status'],
                    $r['status'], $r['registered_at'], $r['last_activity_at'],
                ]);
            }
            fclose($out);
        }, 'customers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** The options the directory's filter row offers, so the client invents none. */
    public function filters()
    {
        return $this->data([
            'statuses' => CustomerService::STATUSES,
            'types' => CustomerService::TYPES,
            'groups' => CustomerGroup::orderBy('name')->get(['id', 'name', 'colour', 'kind', 'discount_percent']),
            'account_managers' => DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.user_id')
                ->distinct()->orderBy('users.name')->get(['users.id', 'users.name']),
            'countries' => Organization::query()
                ->join('billing_profiles', 'billing_profiles.organization_id', '=', 'organizations.id')
                ->whereNotNull('billing_profiles.country')->distinct()
                ->orderBy('billing_profiles.country')->pluck('billing_profiles.country'),
        ]);
    }

    /** @return Builder<Organization> */
    private function filtered(Request $request): Builder
    {
        $q = Organization::query()->with(['memberships.user', 'subscription.plan', 'billingProfile', 'accountManager']);

        foreach (['status' => 'status', 'customer_type' => 'customer_type', 'onboarding_status' => 'onboarding_status'] as $param => $column) {
            if (filled($v = $request->query($param))) {
                $q->where($column, $v);
            }
        }
        if (filled($m = $request->query('account_manager_id'))) {
            $q->where('account_manager_id', $m);
        }
        if (filled($g = $request->query('group_id'))) {
            $q->whereIn('id', DB::table('customer_group_members')->where('customer_group_id', $g)->pluck('organization_id'));
        }
        if (filled($c = $request->query('country'))) {
            $q->whereIn('id', DB::table('billing_profiles')->where('country', $c)->pluck('organization_id'));
        }
        if (filled($from = $request->query('from'))) {
            $q->where('created_at', '>=', $from);
        }
        if (filled($to = $request->query('to'))) {
            $q->where('created_at', '<=', $to);
        }
        // Expiring services is a question about subscriptions, so it is one
        // subquery rather than a column kept in step by hand.
        if ($request->boolean('expiring')) {
            $q->whereIn('id', Subscription::allTenants()->whereIn('status', ['active', 'trialing'])
                ->whereBetween('current_period_end', [now(), now()->addDays(30)])->pluck('organization_id'));
        }
        // The archive is a separate view, not a filter people have to remember.
        $request->boolean('archived') ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');

        return $q;
    }

    private function find(string $id): Organization
    {
        return Organization::with(['memberships.user', 'subscription.plan', 'billingProfile', 'accountManager'])->findOrFail($id);
    }
}
