<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Support\Rbac;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function show(Request $request)
    {
        return $this->data($this->present($request));
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'organization' => 'sometimes|string|max:120',
            'photo_url' => 'nullable|url|max:500',
            'billing_email' => 'nullable|email',
            'new_signin_alerts' => 'sometimes|boolean',
        ]);
        $user->update(collect($data)->only(['name', 'email', 'photo_url'])->all());
        $org = Tenant::current();
        if (isset($data['organization']) || array_key_exists('billing_email', $data)) {
            $this->authorizeFor($request, 'app.team');
            $org->update(array_filter(['name' => $data['organization'] ?? null, 'billing_email' => $data['billing_email'] ?? null]));
        }

        return $this->data($this->present($request));
    }

    public function team(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'role' => ['required', Rule::in(config('rbac.customer_roles'))], 'name' => 'nullable|string']);

        return $this->data(UserController::invite($data['email'], $data['role'], $data['name'] ?? null), 201);
    }

    public function tokens(Request $request)
    {
        return app(ApiTokenController::class)->index($request);
    }

    public function createToken(Request $request)
    {
        return app(ApiTokenController::class)->store($request, app(Rbac::class));
    }

    public function deleteToken(Request $request, string $id)
    {
        return app(ApiTokenController::class)->destroy($request, $id);
    }

    private function present(Request $request): array
    {
        $user = $request->user()->fresh();
        $org = Tenant::current()->fresh();

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'photo_url' => $user->photo_url, 'mfa_enabled' => $user->mfa_enabled],
            'organization' => ['id' => $org->id, 'name' => $org->name, 'billing_email' => $org->billing_email],
            'role' => $user->roleName(),
            'team' => Membership::with('user')->get()->map(fn ($m) => UserController::present($m))->values(),
        ];
    }
}
