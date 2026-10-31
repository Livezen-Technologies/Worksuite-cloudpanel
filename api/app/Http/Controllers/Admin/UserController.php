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
            'created_at' => $m->created_at?->toIso8601ZuluString(), 'invited' => (bool) $m->invite_token_hash,
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

        $url = rtrim(config('platform.panel_url'), '/').'/invite/'.$token;
        // EML-1: the invitation email; a mail failure must not lose the invite (the URL is returned too).
        try {
            app(\App\Services\EmailTemplateService::class)->send('invite', $user, [
                'inviter' => (string) (auth()->user()?->name ?? 'An administrator'), 'organization' => (string) \App\Models\Organization::find($membership->organization_id)?->name,
                'role' => str_replace('_', ' ', $role), 'accept_url' => $url,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return self::present($membership->load('user')) + ['invite_url' => $url];
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
        if ($id === $request->user()->id) {
            throw ApiException::conflict('You cannot change your own role; ask another owner.');
        }
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

    /** Emails the member a password reset link (the same one-hour link as "Forgot password"). */
    public function sendPasswordReset(Request $request, string $id)
    {
        $membership = $this->member($request, $id);
        app(\App\Http\Controllers\AccountRecoveryController::class)->sendReset($membership->user);
        $this->log('users.password_reset_sent', $membership);

        return $this->data(['sent' => true, 'email' => $membership->user->email]);
    }

    /** Turns off the member's two-factor so they can set it up again (a lost phone). */
    public function resetMfa(Request $request, string $id)
    {
        $membership = $this->member($request, $id);
        $membership->user->forceFill(['mfa_enabled' => false, 'mfa_secret' => null, 'mfa_recovery_codes' => null])->save();
        $membership->user->tokens()->where('name', 'session')->delete();
        rescue(fn () => app(\App\Services\EmailTemplateService::class)->notify('two_factor_disabled', $membership->user, ['time' => now()->toDayDateTimeString().' UTC']), report: true);
        $this->log('users.mfa_reset', $membership);

        return $this->data(self::present($membership->fresh()->load('user')));
    }

    /** Suspends or reactivates the member here: a suspended member cannot sign in to this organisation. */
    public function status(Request $request, string $id)
    {
        $data = $request->validate(['status' => 'required|in:active,suspended']);
        $membership = $this->member($request, $id);
        if ($data['status'] === 'suspended') {
            $this->guardLastOwner($membership, null);
            $membership->user->tokens()->where('organization_id', $membership->organization_id)->delete();
        }
        $membership->update(['status' => $data['status']]);
        $this->log('users.'.($data['status'] === 'suspended' ? 'suspend' : 'reactivate'), $membership);

        return $this->data(self::present($membership->load('user')));
    }

    /** Sends a new invitation link (the old one stops working). */
    public function resendInvite(Request $request, string $id)
    {
        $membership = $this->member($request, $id, false);
        if (! $membership->invite_token_hash) {
            throw ApiException::conflict('This member has already accepted the invitation.');
        }
        $token = Str::random(40);
        $membership->update(['invite_token_hash' => hash('sha256', $token)]);
        $url = rtrim(config('platform.panel_url'), '/').'/invite/'.$token;
        rescue(fn () => app(\App\Services\EmailTemplateService::class)->send('invite', $membership->user, [
            'inviter' => (string) ($request->user()->name ?? 'An administrator'), 'organization' => (string) \App\Models\Organization::find($membership->organization_id)?->name,
            'role' => str_replace('_', ' ', $membership->role), 'accept_url' => $url,
        ]), report: true);
        $this->log('users.invite_resent', $membership);

        return $this->data(self::present($membership->load('user')) + ['invite_url' => $url]);
    }

    /** A member of this organisation; nobody acts on themselves here (use the account page). */
    private function member(Request $request, string $id, bool $notSelf = true): Membership
    {
        if ($notSelf && $id === $request->user()->id) {
            throw ApiException::conflict('Use your account page to change your own sign-in.');
        }
        $membership = Membership::with('user')->where('user_id', $id)->firstOrFail();
        if ($membership->role === 'super_admin' && $request->user()->roleName() !== 'super_admin') {
            throw ApiException::forbidden('Only a Super Admin can change a Super Admin.');
        }

        return $membership;
    }

    private function log(string $action, Membership $m): void
    {
        app(\App\Services\AuditLogger::class)->log($action, 'users', $m->user_id, ['email' => $m->user?->email]);
    }

    private function guardLastOwner(Membership $membership, ?string $newRole): void
    {
        if ($membership->role === 'owner' && $newRole !== 'owner' && Membership::where('role', 'owner')->count() <= 1) {
            throw ApiException::conflict('An organisation needs at least one owner.');
        }
    }
}
