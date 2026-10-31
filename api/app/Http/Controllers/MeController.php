<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in person's own profile, password and sessions (admin panel and customer panel alike).
 * Changing the email or the password needs the current password; a new email must be verified again.
 */
class MeController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function profile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|string|min:1|max:120',
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => 'required_with:email|nullable|string',
        ]);
        $changes = [];
        if (isset($data['name']) && $data['name'] !== $user->name) {
            $changes['name'] = $data['name'];
        }
        if (isset($data['email']) && strtolower($data['email']) !== strtolower($user->email)) {
            if (! Hash::check((string) ($data['current_password'] ?? ''), $user->password)) {
                throw ApiException::validation(['current_password' => ['Your current password is not correct.']]);
            }
            $changes['email'] = strtolower($data['email']);
            $changes['email_verified_at'] = null;
        }
        if ($changes) {
            $user->forceFill($changes)->save();
            $this->audit->log('me.profile', 'users', $user->id, array_keys(array_diff_key($changes, ['email_verified_at' => 1])), null, $user->id, $user->email);
            if (isset($changes['email'])) {
                rescue(fn () => app(AccountRecoveryController::class)->sendVerification($user->fresh()), report: true);
            }
        }

        return $this->data($this->present($user->fresh()));
    }

    public function password(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'string', 'confirmed', Password::min(10), 'different:current_password'],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ApiException::validation(['current_password' => ['Your current password is not correct.']]);
        }
        $user->forceFill(['password' => $data['password']])->save();
        // Every other session ends; the one that changed it stays signed in.
        $signedOut = $user->tokens()->where('name', 'session')->whereKeyNot($user->currentAccessToken()?->getKey())->delete();
        $this->audit->log('me.password', 'users', $user->id, ['other_sessions_signed_out' => $signedOut], null, $user->id, $user->email);

        return $this->data(['changed' => true, 'other_sessions_signed_out' => $signedOut]);
    }

    public function sessions(Request $request)
    {
        $current = $request->user()->currentAccessToken()?->getKey();

        return $this->data($request->user()->tokens()->where('name', 'session')->orderByDesc('last_used_at')->get()
            ->map(fn ($t) => ['id' => $t->id, 'current' => $t->id === $current, 'created_at' => $t->created_at?->toIso8601ZuluString(),
                'last_used_at' => $t->last_used_at?->toIso8601ZuluString(), 'expires_at' => $t->expires_at?->toIso8601ZuluString()])->values());
    }

    /** Signs out one session, or (no id) every session but this one. */
    public function endSession(Request $request, ?string $id = null)
    {
        $id ??= 'others';
        $user = $request->user();
        $current = $user->currentAccessToken()?->getKey();
        $q = $user->tokens()->where('name', 'session')->whereKeyNot($current);
        $n = $id === 'others' ? $q->delete() : $q->whereKey($id)->delete();
        if ($id !== 'others' && ! $n) {
            abort(404);
        }
        $this->audit->log('me.sessions.end', 'users', $user->id, ['count' => $n], null, $user->id, $user->email);

        return $this->data(['signed_out' => $n]);
    }

    private function present($user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'email_verified' => (bool) $user->email_verified_at, 'mfa_enabled' => (bool) $user->mfa_enabled];
    }
}
