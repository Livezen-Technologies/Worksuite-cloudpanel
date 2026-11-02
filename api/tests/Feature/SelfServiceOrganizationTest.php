<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use Tests\TestCase;

/**
 * Creating an organisation for yourself from the portal's account menu, and
 * acting as another one you belong to.
 *
 * The thing worth guarding here is that the *token* moves too: SetTenant reads
 * the access token's organization_id before the user's current_organization_id,
 * so moving only the user would leave the session in the old organisation.
 */
class SelfServiceOrganizationTest extends TestCase
{
    public function test_creating_one_makes_you_its_owner_and_moves_the_session_into_it(): void
    {
        [$user, $first, $token] = $this->org();

        $created = $this->api($token)->postJson('/api/v1/organizations', ['name' => 'Harbour Ltd'])
            ->assertStatus(201)->assertJsonPath('data.name', 'Harbour Ltd')->json('data');

        $this->assertNotSame($first->id, $created['id']);
        $this->assertSame('owner', Membership::allTenants()->where('user_id', $user->id)->where('organization_id', $created['id'])->value('role'));
        $this->assertSame($created['id'], $user->fresh()->current_organization_id);

        // The same token now acts as the new organisation, with no new sign-in.
        $this->api($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.organization.id', $created['id']);
    }

    public function test_a_blank_or_duplicate_name_is_rejected_and_the_slug_stays_unique(): void
    {
        [, , $token] = $this->org();
        $this->api($token)->postJson('/api/v1/organizations', ['name' => 'a'])->assertStatus(422);

        $one = $this->api($token)->postJson('/api/v1/organizations', ['name' => 'Harbour Ltd'])->assertStatus(201)->json('data.slug');
        $two = $this->api($token)->postJson('/api/v1/organizations', ['name' => 'Harbour Ltd'])->assertStatus(201)->json('data.slug');
        $this->assertNotSame($one, $two);
    }

    public function test_you_can_switch_only_to_an_organisation_you_belong_to(): void
    {
        [, $mine, $token] = $this->org();
        [, $theirs] = $this->org();

        $this->api($token)->postJson('/api/v1/me/organization', ['organization_id' => $theirs->id])->assertStatus(403);
        $this->api($token)->postJson('/api/v1/me/organization', ['organization_id' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(403);

        $new = $this->api($token)->postJson('/api/v1/organizations', ['name' => 'Second'])->assertStatus(201)->json('data.id');
        $this->api($token)->postJson('/api/v1/me/organization', ['organization_id' => $mine->id])->assertOk()->assertJsonPath('data.id', $mine->id);
        $this->api($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.organization.id', $mine->id);

        // And back again, so the move is not one-way.
        $this->api($token)->postJson('/api/v1/me/organization', ['organization_id' => $new])->assertOk();
        $this->api($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.organization.id', $new);
    }

    public function test_the_new_organisation_is_empty_and_separate(): void
    {
        [, $first, $token] = $this->org();
        $created = $this->api($token)->postJson('/api/v1/organizations', ['name' => 'Harbour Ltd'])->assertStatus(201)->json('data.id');

        $this->assertSame(1, Organization::allTenants()->where('id', $created)->count());
        // Projects made in one are invisible from the other.
        $this->api($token)->postJson('/api/v1/app/projects', ['name' => 'Only here'])->assertStatus(201);
        $this->api($token)->postJson('/api/v1/me/organization', ['organization_id' => $first->id])->assertOk();
        $this->assertCount(0, $this->api($token)->getJson('/api/v1/app/projects')->assertOk()->json('data.projects'));
    }
}
