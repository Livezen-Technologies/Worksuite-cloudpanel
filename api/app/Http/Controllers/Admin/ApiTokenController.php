<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessToken;
use App\Support\Rbac;
use Illuminate\Http\Request;

/** SET-004 / UACC-004: scoped API tokens with expiry and revoke. Shown once. */
class ApiTokenController extends Controller
{
    public function index(Request $request)
    {
        return $this->data($this->query($request)->where('name', '!=', 'session')->latest()->get()->map(fn (AccessToken $t) => [
            'id' => $t->id, 'name' => $t->name, 'abilities' => $t->abilities, 'last_used_at' => $t->last_used_at?->toIso8601ZuluString(),
            'expires_at' => $t->expires_at?->toIso8601ZuluString(), 'created_at' => $t->created_at?->toIso8601ZuluString(),
        ]));
    }

    public function store(Request $request, Rbac $rbac)
    {
        $user = $request->user();
        $allowed = array_merge(['read', '*'], $rbac->permissionsFor($user->roleName()));
        $data = $request->validate([
            'name' => 'required|string|max:120|not_in:session',
            'abilities' => 'required|array|min:1', 'abilities.*' => ['string', 'in:'.implode(',', $allowed)],
            'expires_at' => 'nullable|date|after:now',
        ]);
        $token = $user->createToken($data['name'], $data['abilities'], isset($data['expires_at']) ? now()->parse($data['expires_at']) : now()->addDays(90));
        $token->accessToken->forceFill(['organization_id' => $user->current_organization_id])->save();

        return $this->data([
            'id' => $token->accessToken->id, 'name' => $data['name'], 'abilities' => $data['abilities'],
            'expires_at' => $token->accessToken->expires_at?->toIso8601ZuluString(), 'token' => $token->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $this->query($request)->findOrFail($id)->delete();

        return response()->noContent();
    }

    private function query(Request $request)
    {
        return AccessToken::where('tokenable_id', $request->user()->id)->where('organization_id', $request->user()->current_organization_id);
    }
}
