<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\OrganizationService;
use App\Support\Impersonation;
use Illuminate\Http\Request;

/**
 * Self-service organisations for the customer portal's account menu: create one,
 * or act as another you already belong to.
 *
 * Tenancy follows the access token's organization_id first and the user's
 * current_organization_id second (see SetTenant), so both actions have to move
 * the *token* as well, or the session would carry on in the old organisation.
 */
class OrganizationController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request, OrganizationService $organizations)
    {
        $user = $request->user();
        $this->refuseSupportSession($user);
        $data = $request->validate(['name' => 'required|string|min:2|max:120']);

        $org = $organizations->create($data['name'], $user);
        $this->adopt($request, $org->id);
        $this->audit->log('organizations.create', 'organizations', $org->id, ['name' => $org->name], $org->id, $user->id, $user->email);

        return $this->data(['id' => $org->id, 'name' => $org->name, 'slug' => $org->slug], 201);
    }

    public function switch(Request $request)
    {
        $user = $request->user();
        $this->refuseSupportSession($user);
        $data = $request->validate(['organization_id' => 'required|uuid']);

        $membership = $user->membership($data['organization_id']);
        if (! $membership || $membership->status !== 'active') {
            // Deliberately the same answer whether or not the organisation exists.
            throw ApiException::forbidden('You are not a member of that organisation.');
        }
        $this->adopt($request, $data['organization_id']);
        $org = Organization::withoutGlobalScopes()->find($data['organization_id']);
        $this->audit->log('organizations.switch', 'organizations', $data['organization_id'], [], $data['organization_id'], $user->id, $user->email);

        return $this->data(['id' => $data['organization_id'], 'name' => $org?->name, 'role' => $membership->role]);
    }

    /** A support session acts as somebody else; it must not reshape their account. */
    private function refuseSupportSession($user): void
    {
        if (Impersonation::staffFor($user)) {
            throw ApiException::forbidden('Support sessions cannot create or change organisations. Ask the account owner.');
        }
    }

    private function adopt(Request $request, string $orgId): void
    {
        $user = $request->user();
        $user->forceFill(['current_organization_id' => $orgId])->save();
        // Tokens are pinned at sign-in, so the one in hand has to follow.
        $request->user()->currentAccessToken()?->forceFill(['organization_id' => $orgId])->save();
    }
}
