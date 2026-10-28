<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Support\Lists;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(Lists::paginate(Membership::with('user'), $request, [], ['created_at', 'role'], ['role', 'status'], fn (Membership $m) => self::present($m)));
    }

    public static function present(Membership $m): array
    {
        return [
            'id' => $m->user_id, 'membership_id' => $m->id, 'name' => $m->user?->name, 'email' => $m->user?->email, 'role' => $m->role, 'status' => $m->status,
            'sign_in_method' => $m->user?->mfa_enabled ? $m->user->sign_in_method.'+mfa' : $m->user?->sign_in_method, 'mfa_enabled' => (bool) $m->user?->mfa_enabled,
            'last_login_at' => $m->user?->last_login_at?->toIso8601ZuluString(), 'last_activity_at' => $m->user?->last_activity_at?->toIso8601ZuluString(),
            'created_at' => $m->created_at?->toIso8601ZuluString(),
        ];
    }

    /** Creates (or reuses) the user and an invited membership. */
    public static function invite(string $email, string $role, ?string $name = null): array
    {
        $email = strtolower($email);
        $user = User::firstOrCreate(['email' => $email], ['name' => $name ?? Str::before($email, '@'), 'password' => Str::random(40)]);
        if (Membership::where('user_id', $user->id)->exists()) {
            throw ApiException::conflict("{$email} is already a member of this organisation.");
        }
        $token = Str::random(40);
        $membership = Membership::create(['user_id' => $user->id, 'role' => $role, 'status' => 'active', 'invite_token_hash' => hash('sha256', $token)]);
        if (! $user->current_organization_id) {
            $user->forceFill(['current_organization_id' => $membership->organization_id])->save();
        }

        return self::present($membership->load('user')) + ['invite_url' => rtrim(config('platform.panel_url'), '/').'/invite/'.$token];
    }

    public function store(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'name' => 'nullable|string|max:120', 'role' => 'required|exists:roles,name']);
        if ($data['role'] === 'super_admin' && $request->user()->roleName() !== 'super_admin') {
            throw ApiException::forbidden('Only a Super Admin can grant Super Admin.');
        }

        return $this->data(self::invite($data['email'], $data['role'], $data['name'] ?? null), 201);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->validate(['role' => 'required|exists:roles,name']);
        $membership = Membership::where('user_id', $id)->firstOrFail();
        if ($data['role'] === 'super_admin' && $request->user()->roleName() !== 'super_admin') {
            throw ApiException::forbidden('Only a Super Admin can grant Super Admin.');
        }
        $this->guardLastOwner($membership, $data['role']);
        $membership->update(['role' => $data['role']]);

        return $this->data(self::present($membership->load('user')));
    }

    public function destroy(Request $request, string $id)
    {
        $membership = Membership::where('user_id', $id)->firstOrFail();
        $this->guardLastOwner($membership, null);
        $membership->delete();
        $membership->user?->tokens()->where('organization_id', $membership->organization_id)->delete();

        return response()->noContent();
    }

    private function guardLastOwner(Membership $membership, ?string $newRole): void
    {
        if ($membership->role === 'owner' && $newRole !== 'owner' && Membership::where('role', 'owner')->count() <= 1) {
            throw ApiException::conflict('An organisation needs at least one owner.');
        }
    }
}
