<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;

class OrganizationService
{
    public function __construct(private AlertService $alerts) {}

    /** Creates an organisation with its owner, default settings, MON-004 rules and a Small plan. */
    public function create(string $name, User $owner, string $role = 'owner'): Organization
    {
        $slug = Str::slug($name) ?: 'org';
        if (Organization::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(5));
        }
        $org = Organization::create(['name' => $name, 'slug' => $slug, 'settings' => config('platform.default_settings'), 'billing_email' => $owner->email,
            'default_region' => config('platform.default_settings.general.default_region')]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => $role, 'status' => 'active']);
        $this->alerts->seedDefaults($org);
        if ($plan = Plan::where('code', 'small')->first()) {
            Subscription::create(['organization_id' => $org->id, 'plan_id' => $plan->id, 'status' => 'active', 'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->endOfMonth()]);
        }
        $owner->forceFill(['current_organization_id' => $org->id])->save();

        return $org;
    }
}
