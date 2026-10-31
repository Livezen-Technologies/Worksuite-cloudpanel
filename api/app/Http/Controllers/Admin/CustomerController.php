<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Hosting customers. An organisation is the tenant; its owner is a hosting user
 * (customer roles land on /app), as opposed to staff who hold platform roles.
 */
class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $this->staffOnly($request);
        $orgs = Organization::orderBy('name')->get();
        // Platform-admin view spans tenants, so the organisation scope must not apply.
        $subs = Subscription::withoutGlobalScopes()->whereIn('organization_id', $orgs->pluck('id'))->get()->keyBy('organization_id');
        $plans = Plan::all()->keyBy('id');

        // CUS-6: counts in grouped queries rather than one per customer.
        $users = DB::table('memberships')->whereIn('organization_id', $orgs->pluck('id'))->groupBy('organization_id')->selectRaw('organization_id, count(*) as total')->pluck('total', 'organization_id');
        $apps = DB::table('applications')->whereNull('deleted_at')->whereIn('organization_id', $orgs->pluck('id'))->groupBy('organization_id')->selectRaw('organization_id, count(*) as total')->pluck('total', 'organization_id');

        return $this->data($orgs->map(function ($o) use ($subs, $plans, $users, $apps) {
            $sub = $subs[$o->id] ?? null;
            $plan = $sub && isset($plans[$sub->plan_id]) ? $plans[$sub->plan_id] : null;
            $appLimit = isset($plan?->limits['apps']) ? (int) $plan->limits['apps'] : null;
            $appCount = (int) ($apps[$o->id] ?? 0);
            $settings = $o->settings ?? [];

            return [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'billing_email' => $o->billing_email,
                'plan' => $plan ? ['id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name] : null,
                'subscription_status' => $sub->status ?? null,
                'status' => ! empty($settings['closed']) ? 'closed' : (($sub->status ?? null) === 'suspended' ? 'suspended' : 'active'),
                'users' => (int) ($users[$o->id] ?? 0),
                'apps' => $appCount,
                'app_limit' => $appLimit,
                'over_limit' => $appLimit !== null && $appCount > $appLimit,
                'created_at' => $o->created_at,
            ];
        }));
    }

    /** Creates the tenant, its owner (a hosting user) and the package subscription in one step. */
    public function store(Request $request)
    {
        $this->staffOnly($request);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'billing_email' => ['required', 'email', 'max:190'],
            'plan_id' => ['required', 'uuid', 'exists:plans,id'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'owner_password' => ['required', 'string', 'min:12'],
        ]);

        $org = DB::transaction(function () use ($d) {
            $org = new Organization();
            $org->name = $d['name'];
            $org->slug = $this->uniqueSlug($d['name']);
            $org->billing_email = $d['billing_email'];
            $org->save();

            $owner = new User();
            $owner->name = $d['owner_name'];
            $owner->email = $d['owner_email'];
            $owner->password = $d['owner_password'];   // 'hashed' cast
            $owner->email_verified_at = now();
            $owner->current_organization_id = $org->id;
            $owner->save();

            DB::table('memberships')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $owner->id, 'organization_id' => $org->id,
                'role' => 'owner', 'created_at' => now(), 'updated_at' => now(),
            ]);

            Subscription::create([
                'organization_id' => $org->id, 'plan_id' => $d['plan_id'], 'status' => 'active',
                'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
            ]);

            return $org;
        });

        return $this->data(['id' => $org->id, 'name' => $org->name, 'slug' => $org->slug], 201);
    }

    /** CUS-1: the whole customer on one page: account, package, people, apps, domains, billing and activity. */
    public function show(Request $request, string $id)
    {
        $this->staffOnly($request);
        $org = Organization::findOrFail($id);
        $sub = Subscription::withoutGlobalScopes()->where('organization_id', $org->id)->first();
        $plan = $sub ? Plan::find($sub->plan_id) : null;
        $apps = \App\Models\Application::allTenants()->where('organization_id', $org->id)->orderBy('name')->get();
        $members = DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('memberships.organization_id', $org->id)
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.email', 'users.mfa_enabled', 'users.last_login_at', 'memberships.role', 'memberships.status']);
        $domains = DB::table('domains')->where('organization_id', $org->id)->orderBy('name')->get(['id', 'name', 'application_id', 'status', 'created_at']);
        $settings = $org->settings ?? [];

        return $this->data([
            'id' => $org->id, 'name' => $org->name, 'slug' => $org->slug, 'billing_email' => $org->billing_email, 'created_at' => $org->created_at,
            'status' => ! empty($settings['closed']) ? 'closed' : (($sub->status ?? null) === 'suspended' ? 'suspended' : 'active'),
            'closed' => $settings['closed'] ?? null,
            'suspension' => $settings['suspension'] ?? null,
            'notes' => $settings['admin_notes'] ?? '',
            'plan' => $plan ? ['id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name, 'limits' => $plan->limits] : null,
            'subscription' => $sub ? ['status' => $sub->status, 'current_period_start' => $sub->current_period_start, 'current_period_end' => $sub->current_period_end] : null,
            'counts' => ['members' => $members->count(), 'apps' => $apps->count(), 'running' => $apps->where('status', 'running')->count(),
                'domains' => $domains->count(), 'projects' => DB::table('projects')->where('organization_id', $org->id)->count(),
                'mailboxes' => DB::table('mailboxes')->where('organization_id', $org->id)->count()],
            'members' => $members->map(fn ($m) => (array) $m + ['mfa_enabled' => (bool) $m->mfa_enabled])->values(),
            'apps' => $apps->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'status' => $a->status, 'image' => $a->image, 'project_id' => $a->project_id, 'last_deployed_at' => $a->last_deployed_at])->values(),
            'projects' => DB::table('projects')->where('organization_id', $org->id)->orderBy('name')->get(['id', 'name', 'created_at']),
            'domains' => $domains,
            'invoices' => DB::table('invoices')->where('organization_id', $org->id)->orderByDesc('period_start')->limit(24)
                ->get(['id', 'number', 'period_start', 'period_end', 'amount_cents', 'currency', 'status', 'pdf_url']),
            'activity' => DB::table('audit_logs')->where('organization_id', $org->id)->orderByDesc('id')->limit(50)
                ->get(['id', 'actor', 'action', 'resource_type', 'ip', 'created_at']),
        ]);
    }

    /** CUS-2: suspend a customer: their apps show the "suspended" page until reactivated. */
    public function suspend(Request $request, string $id)
    {
        $this->staffOnly($request);
        $org = Organization::findOrFail($id);
        if (! empty(($org->settings ?? [])['closed'])) {
            throw \App\Exceptions\ApiException::conflict('This account is closed. Closed accounts cannot be changed here.');
        }
        $d = $request->validate(['reason' => 'required|string|max:300']);
        DB::transaction(function () use ($org, $d, $request) {
            $apps = \App\Models\Application::allTenants()->where('organization_id', $org->id)->where('status', '!=', 'suspended')->get(['id', 'status']);
            $settings = $org->settings ?? [];
            $settings['suspension'] = ['reason' => $d['reason'], 'at' => now()->toIso8601ZuluString(), 'by' => $request->user()->email,
                'previous' => array_merge($settings['suspension']['previous'] ?? [], $apps->pluck('status', 'id')->all())];
            $org->forceFill(['settings' => $settings])->save();
            \App\Models\Application::allTenants()->whereIn('id', $apps->pluck('id'))->update(['status' => 'suspended']);
            Subscription::withoutGlobalScopes()->where('organization_id', $org->id)->update(['status' => 'suspended']);
        });
        app(\App\Services\AuditLogger::class)->log('customer.suspend', 'organizations', $org->id, ['reason' => $d['reason']], $org->id);
        rescue(fn () => app(\App\Services\TraefikConfigService::class)->push(), report: true);

        return $this->show($request, $org->id);
    }

    public function reactivate(Request $request, string $id)
    {
        $this->staffOnly($request);
        $org = Organization::findOrFail($id);
        if (! empty(($org->settings ?? [])['closed'])) {
            throw \App\Exceptions\ApiException::conflict('This account is closed. Closed accounts cannot be changed here.');
        }
        DB::transaction(function () use ($org) {
            $settings = $org->settings ?? [];
            foreach ($settings['suspension']['previous'] ?? [] as $appId => $status) {
                \App\Models\Application::allTenants()->where('id', $appId)->where('status', 'suspended')->update(['status' => $status ?: 'running']);
            }
            unset($settings['suspension']);
            $org->forceFill(['settings' => $settings])->save();
            Subscription::withoutGlobalScopes()->where('organization_id', $org->id)->where('status', 'suspended')->update(['status' => 'active']);
        });
        app(\App\Services\AuditLogger::class)->log('customer.reactivate', 'organizations', $org->id, [], $org->id);
        rescue(fn () => app(\App\Services\TraefikConfigService::class)->push(), report: true);

        return $this->show($request, $org->id);
    }

    public function update(Request $request, string $id)
    {
        $this->staffOnly($request);
        $org = Organization::findOrFail($id);
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'billing_email' => ['sometimes', 'email', 'max:190'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        if (array_key_exists('notes', $d)) {
            $settings = $org->settings ?? [];
            $settings['admin_notes'] = (string) $d['notes'];
            $org->forceFill(['settings' => $settings]);
            unset($d['notes']);
        }
        $org->fill($d)->save();

        return $this->data(['id' => $org->id, 'name' => $org->name, 'billing_email' => $org->billing_email]);
    }

    /** Move a customer onto another package. */
    public function setPlan(Request $request, string $id)
    {
        $this->staffOnly($request);
        $org = Organization::findOrFail($id);
        $d = $request->validate(['plan_id' => ['required', 'uuid', 'exists:plans,id']]);

        $sub = Subscription::withoutGlobalScopes()->firstOrNew(['organization_id' => $org->id]);
        $sub->plan_id = $d['plan_id'];
        $sub->status = 'active';
        $sub->current_period_start = $sub->current_period_start ?? now();
        $sub->current_period_end = $sub->current_period_end ?? now()->addMonth();
        $sub->save();

        $plan = Plan::find($d['plan_id']);

        return $this->data(['organization_id' => $org->id, 'plan' => ['id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name]]);
    }

    /** Customer profiles span tenants: platform staff only. */
    /** CUS-7: invite someone into the customer's organisation. Only customer-level roles can be given. */
    public function addMember(Request $request, string $id)
    {
        $this->staffOnly($request);
        $org = Organization::findOrFail($id);
        if (! empty(($org->settings ?? [])['closed'])) {
            throw \App\Exceptions\ApiException::conflict('This account is closed.');
        }
        $platformRoles = array_merge(array_keys(array_filter(config('rbac.roles'), fn ($r) => ($r['scope'] ?? '') === 'platform')),
            \App\Models\Role::where('scope', 'platform')->pluck('name')->all(), ['super_admin']);
        $d = $request->validate(['email' => 'required|email|max:190', 'name' => 'nullable|string|max:120', 'role' => ['required', 'exists:roles,name', \Illuminate\Validation\Rule::notIn($platformRoles)]]);
        $member = app(\App\Support\Tenant::class)->as($org->id, fn () => \App\Http\Controllers\Admin\UserController::invite($d['email'], $d['role'], $d['name'] ?? null));
        app(\App\Services\AuditLogger::class)->log('customer.member_invited', 'organizations', $org->id, ['email' => strtolower($d['email']), 'role' => $d['role'], 'by' => $request->user()->email], $org->id);

        return $this->data($member, 201);
    }

    /** CUS-4: email a member of the customer a password-reset link (when they are locked out and ask support). */
    public function sendPasswordReset(Request $request, string $id, string $userId)
    {
        $this->staffOnly($request);
        $org = Organization::findOrFail($id);
        $isMember = \App\Models\Membership::allTenants()->where('organization_id', $org->id)->where('user_id', $userId)->exists();
        $user = $isMember ? \App\Models\User::find($userId) : null;
        if (! $user) {
            throw new \App\Exceptions\ApiException('not_found', 'That person is not a member of this customer.', 404);
        }
        app(\App\Http\Controllers\AccountRecoveryController::class)->sendReset($user);
        app(\App\Services\AuditLogger::class)->log('customer.password_reset_sent', 'users', $user->id, ['to' => $user->email, 'by' => $request->user()->email], $org->id);

        return $this->data(['sent' => true, 'email' => $user->email]);
    }

    /**
     * CUS-5: close a customer account. Their apps are retired (containers removed, volumes and backups
     * kept for the retention period), domains removed, the subscription cancelled and members signed out.
     * The name must be typed to confirm; a closed account cannot be reactivated from here.
     */
    public function close(Request $request, string $id)
    {
        $this->staffOnly($request);
        $org = Organization::findOrFail($id);
        $d = $request->validate(['reason' => 'required|string|min:3|max:300', 'confirm' => 'required|string']);
        if (trim($d['confirm']) !== $org->name) {
            throw \App\Exceptions\ApiException::validation(['confirm' => ["Type the customer name exactly: {$org->name}"]]);
        }
        if (! empty(($org->settings ?? [])['closed'])) {
            throw \App\Exceptions\ApiException::conflict('This account is already closed.');
        }
        if ($org->id === $request->user()->current_organization_id) {
            throw \App\Exceptions\ApiException::conflict('You cannot close your own organisation.');
        }
        $retired = app(\App\Support\Tenant::class)->as($org->id, function () use ($org) {
            $apps = \App\Models\Application::allTenants()->where('organization_id', $org->id)->get();
            foreach ($apps as $app) {
                $app->retire();
            }
            \App\Models\Domain::allTenants()->where('organization_id', $org->id)->get()->each->delete();

            return $apps->count();
        });
        DB::transaction(function () use ($org, $d, $request) {
            Subscription::withoutGlobalScopes()->where('organization_id', $org->id)->update(['status' => 'canceled']);
            \App\Models\Membership::allTenants()->where('organization_id', $org->id)->update(['status' => 'suspended']);
            \App\Models\AccessToken::where('organization_id', $org->id)->delete();
            $settings = $org->settings ?? [];
            $settings['closed'] = ['reason' => $d['reason'], 'at' => now()->toIso8601ZuluString(), 'by' => $request->user()->email];
            $org->forceFill(['settings' => $settings])->save();
        });
        app(\App\Services\AuditLogger::class)->log('customer.close', 'organizations', $org->id, ['reason' => $d['reason'], 'apps_retired' => $retired], $org->id);
        rescue(fn () => app(\App\Services\TraefikConfigService::class)->push(), report: true);

        return $this->show($request, $org->id);
    }

    /**
     * CUS-3: platform staff sign in as the customer's owner to see what they see. Needs a reason, lasts an
     * hour, is audited on both sides, emails the owner, and cannot touch the owner's sign-in settings.
     */
    public function impersonate(Request $request, string $id)
    {
        $this->staffOnly($request);
        $org = Organization::findOrFail($id);
        $d = $request->validate(['reason' => 'required|string|min:5|max:300']);
        $staff = $request->user();
        $member = \App\Models\Membership::allTenants()->where('organization_id', $org->id)->where('status', 'active')
            ->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 else 2 end")->first();
        $target = $member ? \App\Models\User::find($member->user_id) : null;
        if (! $target) {
            throw \App\Exceptions\ApiException::conflict('This customer has no active member to sign in as.');
        }
        if ($target->id === $staff->id) {
            throw \App\Exceptions\ApiException::conflict('You are already a member of this customer.');
        }
        $expires = now()->addHour();
        $token = $target->createToken(\App\Support\Impersonation::PREFIX.$staff->id, ['*'], $expires);
        $token->accessToken->forceFill(['organization_id' => $org->id])->save();
        $meta = ['reason' => $d['reason'], 'as' => $target->email, 'by' => $staff->email, 'expires_at' => $expires->toIso8601ZuluString()];
        app(\App\Services\AuditLogger::class)->log('customer.impersonate', 'organizations', $org->id, $meta, $org->id, $staff->id, $staff->email);
        app(\App\Services\AuditLogger::class)->log('customer.impersonate', 'organizations', $org->id, $meta, $staff->current_organization_id, $staff->id, $staff->email);
        app(\App\Services\EmailTemplateService::class)->notify('support_access', $target, [
            'staff' => $staff->name ?: $staff->email, 'reason' => $d['reason'], 'time' => now()->toDayDateTimeString().' UTC', 'minutes' => '60',
        ]);

        return $this->data(['token' => $token->plainTextToken, 'user' => ['name' => $target->name, 'email' => $target->email], 'organization' => $org->name, 'expires_at' => $meta['expires_at']]);
    }

    private function staffOnly(Request $request): void
    {
        if (! $this->seesAllTenants($request)) {
            throw \App\Exceptions\ApiException::forbidden('Only platform staff can manage customers.');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'customer';
        $slug = $base;
        for ($i = 2; Organization::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
