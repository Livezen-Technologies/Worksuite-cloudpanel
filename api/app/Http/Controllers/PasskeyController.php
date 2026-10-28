<?php

namespace App\Http\Controllers;

use App\Models\Passkey;
use App\Models\User;
use App\Services\WebAuthnService;
use Illuminate\Http\Request;

/** Passkeys: sign-in, second factor and management on the Account page (SEC-002, UACC-002). */
class PasskeyController extends Controller
{
    public function __construct(private WebAuthnService $webauthn) {}

    /** POST /auth/passkey/options {email?} — with an email only that account's passkeys are offered (second factor). */
    public function loginOptions(Request $request)
    {
        $data = $request->validate(['email' => 'nullable|email']);
        $user = ! empty($data['email']) ? User::where('email', strtolower($data['email']))->first() : null;

        return $this->data($this->webauthn->authenticationOptions($user));
    }

    /** POST /auth/passkey/login {challenge_id, credential} — passwordless sign-in with a discoverable passkey. */
    public function login(Request $request, AuthController $auth)
    {
        $data = $request->validate(['challenge_id' => 'required|string', 'credential' => 'required|array']);
        $user = $this->webauthn->authenticate($data['challenge_id'], $data['credential']);

        return $auth->completeLogin($user, 'passkey');
    }

    public function index(Request $request)
    {
        return $this->data(Passkey::where('user_id', $request->user()->id)->orderBy('created_at')->get()->map(fn ($p) => WebAuthnService::present($p))->values());
    }

    public function registrationOptions(Request $request)
    {
        return $this->data($this->webauthn->registrationOptions($request->user()));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['challenge_id' => 'required|string', 'credential' => 'required|array', 'name' => 'nullable|string|max:80']);
        $passkey = $this->webauthn->register($request->user(), $data['challenge_id'], $data['credential'], $data['name'] ?? null);

        return $this->data(WebAuthnService::present($passkey), 201);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->validate(['name' => 'required|string|max:80']);
        $passkey = Passkey::where('user_id', $request->user()->id)->findOrFail($id);
        $passkey->update(['name' => $data['name']]);

        return $this->data(WebAuthnService::present($passkey));
    }

    public function destroy(Request $request, string $id)
    {
        Passkey::where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return response()->noContent();
    }
}
