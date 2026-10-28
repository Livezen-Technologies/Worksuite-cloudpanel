<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Plan;
use App\Models\Region;
use App\Models\Role;
use App\Support\Rbac;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Roles/permissions matrix (SRS 3.1/3.2), plan catalogue and regions. Idempotent. */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [];
        foreach (config('rbac.permissions') as $name => [$label, $group]) {
            $permissions[$name] = Permission::updateOrCreate(['name' => $name], ['label' => $label, 'group' => $group]);
        }
        foreach (config('rbac.roles') as $name => $def) {
            $role = Role::updateOrCreate(['name' => $name], ['label' => $def['label'], 'scope' => $def['scope']]);
            $grants = $def['grants'] === '*' ? array_fill_keys(array_keys($permissions), 'all') : $def['grants'];
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            foreach ($grants as $permission => $grant) {
                $parsed = Rbac::parse($grant);
                DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission_id' => $permissions[$permission]->id] + $parsed);
            }
        }
        app(Rbac::class)->flush();

        foreach (config('platform.plans') as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan);
        }
        foreach (['eu-central' => 'EU Central (Falkenstein)', 'eu-west' => 'EU West (Amsterdam)', 'us-east' => 'US East (Ashburn)'] as $code => $name) {
            Region::updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
