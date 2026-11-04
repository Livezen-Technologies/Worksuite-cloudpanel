<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Membership;
use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** The link in an invitation email: who invited you where, then set a name and password. Single use. */
class InviteController extends Controller
{
    public function show(string $token)
    {
        $m = $this->membership($token);

        return $this->data(['email' => $m->user->email, 'name' => $m->user->name, 'organization' => Organization::find($m->organization_id)?->name, 'role' => $m->role,
            'has_account' => $this->hasAccount($m)]);
    }

    public function accept(Request $request, string $token)
    {
        $m = $this->membership($token);
        if ($this->hasAccount($m)) {
            // An existing account only joins: an invitation link never changes someone's password.
            $m->user->forceFill(['current_organization_id' => $m->user->current_organization_id ?? $m->organization_id])->save();
        } else {
            $data = $request->validate(['name' => 'required|string|max:120', 'password' => ['required', 'string', 'confirmed', Password::min(10)]]);
            $m->user->forceFill(['name' => $data['name'], 'password' => $data['password'], 'email_verified_at' => $m->user->email_verified_at ?? now(),
                'current_organization_id' => $m->user->current_organization_id ?? $m->organization_id])->save();
        }
        $m->forceFill(['invite_token_hash' => null])->save();
        app(AuditLogger::class)->log('users.invite_accepted', 'users', $m->user_id, [], $m->organization_id, $m->user_id, $m->user->email);

        return $this->data(['accepted' => true, 'email' => $m->user->email]);
    }

    /** Someone who has signed in before, or belongs to another organisation, already has a password. */
    private function hasAccount(Membership $m): bool
    {
        return (bool) $m->user->last_login_at || Membership::withoutGlobalScope('organization')->where('user_id', $m->user_id)->whereKeyNot($m->id)->exists();
    }

    private function membership(string $token): Membership
    {
        $m = Membership::withoutGlobalScope('organization')->with('user')->where('invite_token_hash', hash('sha256', $token))->first();
        if (! $m || ! $m->user || $m->updated_at?->lt(now()->subDays(14))) {
            throw ApiException::validation(['token' => ['This invitation is not valid or has expired. Ask for a new one.']]);
        }

        return $m;
    }
}
