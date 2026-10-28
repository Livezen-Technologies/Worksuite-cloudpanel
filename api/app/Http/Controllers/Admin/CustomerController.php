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
    public function index()
    {
        $orgs = Organization::orderBy('name')->get();
        // Platform-admin view spans tenants, so the organisation scope must not apply.
        $subs = Subscription::withoutGlobalScopes()->whereIn('organization_id', $orgs->pluck('id'))->get()->keyBy('organization_id');
        $plans = Plan::all()->keyBy('id');

        return $this->data($orgs->map(function ($o) use ($subs, $plans) {
            $sub = $subs[$o->id] ?? null;
            $plan = $sub && isset($plans[$sub->plan_id]) ? $plans[$sub->plan_id] : null;

            return [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'billing_email' => $o->billing_email,
                'plan' => $plan ? ['id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name] : null,
                'subscription_status' => $sub->status ?? null,
                'users' => DB::table('memberships')->where('organization_id', $o->id)->count(),
                'created_at' => $o->created_at,
            ];
        }));
    }

    /** Creates the tenant, its owner (a hosting user) and the package subscription in one step. */
    public function store(Request $request)
    {
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

    public function update(Request $request, string $id)
    {
        $org = Organization::findOrFail($id);
        $d = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'billing_email' => ['sometimes', 'email', 'max:190'],
        ]);
        $org->update($d);

        return $this->data(['id' => $org->id, 'name' => $org->name, 'billing_email' => $org->billing_email]);
    }

    /** Move a customer onto another package. */
    public function setPlan(Request $request, string $id)
    {
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
