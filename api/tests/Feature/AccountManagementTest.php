<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Your own profile, password and sessions; managing staff; accepting an invitation. */
class AccountManagementTest extends TestCase
{
    private array $sent = [];

    private function mails(): void
    {
        Event::listen(MessageSent::class, function ($e) {
            $this->sent[] = [$e->message->getTo()[0]->getAddress(), $e->message->getSubject(), $e->message->getTextBody()];
        });
    }

    public function test_profile_email_change_needs_the_password_and_a_new_verification(): void
    {
        [$user, , $token] = $this->org();
        $this->api($token)->patchJson('/api/v1/me/profile', ['name' => 'Hasarinda Manjula'])->assertOk()->assertJsonPath('data.name', 'Hasarinda Manjula');
        $this->api($token)->patchJson('/api/v1/me/profile', ['email' => 'new@example.test'])->assertStatus(422);
        $this->api($token)->patchJson('/api/v1/me/profile', ['email' => 'new@example.test', 'current_password' => 'wrong'])->assertStatus(422)->assertJsonPath('error.fields.current_password.0', 'Your current password is not correct.');
        $this->api($token)->patchJson('/api/v1/me/profile', ['email' => 'New@Example.test', 'current_password' => 'password'])->assertOk()
            ->assertJsonPath('data.email', 'new@example.test')->assertJsonPath('data.email_verified', false);
        [, , $other] = $this->org();
        $this->api($other)->patchJson('/api/v1/me/profile', ['email' => 'new@example.test', 'current_password' => 'password'])->assertStatus(422);
    }

    public function test_changing_the_password_signs_out_the_other_sessions_only(): void
    {
        [$user, , $token] = $this->org();
        $second = $this->tokenFor($user);
        $this->assertCount(2, $this->api($token)->getJson('/api/v1/me/sessions')->assertOk()->json('data'));
        $this->api($token)->postJson('/api/v1/me/password', ['current_password' => 'nope', 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->api($token)->postJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'])
            ->assertOk()->assertJsonPath('data.other_sessions_signed_out', 1);
        $this->assertTrue(Hash::check('a-new-long-password', $user->fresh()->password));
        $this->app['auth']->forgetGuards();
        $this->api($second)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $sessions = $this->api($token)->getJson('/api/v1/me/sessions')->json('data');
        $this->assertSame([true], array_column($sessions, 'current'));
    }

    public function test_ending_sessions(): void
    {
        [$user, , $token] = $this->org();
        $this->tokenFor($user);
        $this->tokenFor($user);
        $ids = collect($this->api($token)->getJson('/api/v1/me/sessions')->json('data'))->where('current', false)->pluck('id')->values();
        $this->api($token)->deleteJson("/api/v1/me/sessions/{$ids[0]}")->assertOk()->assertJsonPath('data.signed_out', 1);
        $this->api($token)->deleteJson('/api/v1/me/sessions')->assertOk()->assertJsonPath('data.signed_out', 1);
        $this->assertCount(1, $this->api($token)->getJson('/api/v1/me/sessions')->json('data'));
    }

    public function test_admins_send_resets_reset_two_factor_and_suspend_members(): void
    {
        $this->mails();
        [$owner, $org, $token] = $this->org();
        [$dev, $devToken] = $this->member($org, 'developer');
        $dev->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'SECRET'])->save();

        $this->api($token)->postJson("/api/v1/users/{$dev->id}/password-reset")->assertOk()->assertJsonPath('data.email', $dev->email);
        $this->assertNotEmpty(array_filter($this->sent, fn ($m) => $m[0] === $dev->email && str_contains($m[2], '/reset-password?')));

        $this->api($token)->postJson("/api/v1/users/{$dev->id}/mfa-reset")->assertOk()->assertJsonPath('data.mfa_enabled', false);
        $this->assertNull($dev->fresh()->mfa_secret);

        $this->api($token)->postJson("/api/v1/users/{$dev->id}/status", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->app['auth']->forgetGuards();
        $this->api($devToken)->getJson('/api/v1/servers')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->api($token)->postJson("/api/v1/users/{$dev->id}/status", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');

        $this->api($token)->postJson("/api/v1/users/{$owner->id}/status", ['status' => 'suspended'])->assertStatus(409);
        $this->api($token)->patchJson("/api/v1/users/{$owner->id}", ['role' => 'viewer'])->assertStatus(409);
        [, $viewerToken] = $this->member($org, 'viewer');
        $this->api($viewerToken)->postJson("/api/v1/users/{$dev->id}/password-reset")->assertForbidden();
    }

    public function test_an_invitation_is_accepted_once_and_never_changes_an_existing_password(): void
    {
        $this->mails();
        [, , $token] = $this->org();
        $this->api($token)->postJson('/api/v1/users/invite', ['email' => 'newbie@example.test', 'role' => 'developer'])->assertCreated();
        $resent = $this->api($token)->postJson('/api/v1/users/'.User::where('email', 'newbie@example.test')->value('id').'/resend-invite')->assertOk()->json('data.invite_url');
        $code = basename($resent);

        $this->getJson("/api/v1/auth/invite/{$code}")->assertOk()->assertJsonPath('data.email', 'newbie@example.test')->assertJsonPath('data.has_account', false);
        $this->postJson("/api/v1/auth/invite/{$code}", ['name' => 'New Person', 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->postJson("/api/v1/auth/invite/{$code}", ['name' => 'New Person', 'password' => 'a-good-password', 'password_confirmation' => 'a-good-password'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'newbie@example.test', 'password' => 'a-good-password'])->assertOk();
        $this->getJson("/api/v1/auth/invite/{$code}")->assertStatus(422);

        // Someone who already has an account just joins.
        [$existing] = $this->org();
        $invite = $this->api($token)->postJson('/api/v1/users/invite', ['email' => $existing->email, 'role' => 'viewer'])->assertCreated()->json('data.invite_url');
        $this->getJson('/api/v1/auth/invite/'.basename($invite))->assertJsonPath('data.has_account', true);
        $this->postJson('/api/v1/auth/invite/'.basename($invite), ['name' => 'x', 'password' => 'hijack-password', 'password_confirmation' => 'hijack-password'])->assertOk();
        $this->assertTrue(Hash::check('password', $existing->fresh()->password));
        $this->assertNull(Membership::allTenants()->where('user_id', $existing->id)->whereNotNull('invite_token_hash')->first());
    }
}
