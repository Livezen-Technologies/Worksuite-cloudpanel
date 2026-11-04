<?php

namespace App\Http\Controllers\Admin\Customers;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CUST-4: the people under one customer account, and what each of them may do.
 *
 * Inviting, re-roling and removing a member already live on
 * Admin\CustomerController (last-owner guard, token revocation, audit trail);
 * the Customers section routes to those rather than growing a second copy. What
 * was missing is the read: a list that says which role each person holds, what
 * that role actually permits, and whether they also belong to other accounts --
 * the last of which is how a shared contractor shows up before somebody is
 * surprised by it.
 */
class AccountUserController extends Controller
{
    public function __construct(private Rbac $rbac) {}

    public function index(Request $request, string $organizationId)
    {
        $org = Organization::findOrFail($organizationId);

        $members = Membership::allTenants()->with('user')
            ->where('organization_id', $org->id)->orderBy('role')->get();

        // One grouped query rather than one per person: how many other accounts
        // each of them belongs to.
        $elsewhere = DB::table('memberships')->whereIn('user_id', $members->pluck('user_id'))
            ->where('organization_id', '!=', $org->id)
            ->select('user_id', DB::raw('count(*) as total'))->groupBy('user_id')->pluck('total', 'user_id');

        $owners = $members->where('role', 'owner')->where('status', 'active')->count();

        return $this->data([
            'users' => $members->map(fn (Membership $m) => [
                'user_id' => $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role,
                'status' => $m->status,
                'permissions' => $this->rbac->permissionsFor($m->role),
                'other_accounts' => (int) ($elsewhere[$m->user_id] ?? 0),
                'last_login_at' => $m->user?->last_login_at?->toIso8601String(),
                'joined_at' => $m->created_at?->toIso8601String(),
                // The UI greys the remove and re-role actions rather than
                // offering them and failing; the API refuses them anyway.
                'is_last_owner' => $m->role === 'owner' && $m->status === 'active' && $owners <= 1,
            ])->values(),
            'roles' => $this->roleCatalogue(),
        ]);
    }

    /**
     * The roles a customer's own people may hold, with the permissions each
     * carries. Platform roles are deliberately absent: a customer's user is
     * never made support staff from this screen.
     */
    private function roleCatalogue(): array
    {
        return Role::where('scope', '!=', 'platform')->orderBy('name')->get()
            ->map(fn (Role $r) => [
                'name' => $r->name,
                'label' => $r->label,
                'description' => $r->description,
                'permissions' => $this->rbac->permissionsFor($r->name),
            ])->values()->all();
    }

    /** The catalogue on its own, for a form that has not loaded an account yet. */
    public function roles()
    {
        return $this->data($this->roleCatalogue());
    }
}
