<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Controller;
use App\Exceptions\ApiException;
use App\Models\AccessToken;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Services\AuditLogger;
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

    /** Change a team member's role (customer roles only; the organisation keeps at least one active owner). */
    public function updateMember(Request $request, string $userId)
    {
        $d = $request->validate(['role' => ['required', Rule::in(config('rbac.customer_roles'))]]);
        $m = $this->member($userId);
        if ($m->role !== $d['role']) {
            $this->assertNotLastOwner($m);
            $from = $m->role;
            $m->update(['role' => $d['role']]);
            app(AuditLogger::class)->log('team.member_role_changed', 'users', $userId, ['from' => $from, 'to' => $d['role'], 'by' => $request->user()->email]);
        }

        return $this->data(UserController::present($m->fresh('user')));
    }

    /** Remove a team member: their membership, project roles and API tokens in this organisation go. */
    public function removeMember(Request $request, string $userId)
    {
        if ($userId === $request->user()->id) {
            throw ApiException::conflict('You cannot remove yourself. Ask another owner to do it.');
        }
        $m = $this->member($userId);
        $this->assertNotLastOwner($m);
        $org = Tenant::current();
        $email = $m->user?->email;
        $m->delete();
        ProjectMember::whereIn('project_id', Project::pluck('id'))->where('user_id', $userId)->delete();
        AccessToken::where('organization_id', $org->id)->where('tokenable_id', $userId)->delete();
        app(AuditLogger::class)->log('team.member_removed', 'users', $userId, ['email' => $email, 'role' => $m->role, 'by' => $request->user()->email]);

        return response()->noContent();
    }

    private function member(string $userId): Membership
    {
        return Membership::with('user')->where('user_id', $userId)->first()
            ?? throw new ApiException('not_found', 'That person is not on your team.', 404);
    }

    private function assertNotLastOwner(Membership $m): void
    {
        if ($m->role === 'owner' && $m->status === 'active' && Membership::where('role', 'owner')->where('status', 'active')->count() <= 1) {
            throw ApiException::conflict('This is the only owner. Make someone else an owner first.');
        }
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
