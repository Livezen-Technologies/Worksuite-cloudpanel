<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Password reset and email verification. */
class AccountRecoveryTest extends TestCase
{
    /** @var array<int, \Symfony\Component\Mime\Email> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.panel_url' => 'https://panel.example.com']);
        Event::listen(MessageSent::class, fn (MessageSent $e) => $this->sent[] = $e->message);
    }

    /** The first link of the given path in the last email's text. */
    private function link(string $path): array
    {
        preg_match('#https://panel\.example\.com'.preg_quote($path, '#').'\?(\S+)#', end($this->sent)->getTextBody(), $m);
        parse_str(html_entity_decode($m[1] ?? ''), $q);

        return $q;
    }

    public function test_a_reset_link_sets_a_new_password_once_and_signs_everyone_out(): void
    {
        [$user] = $this->org();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => strtoupper($user->email)])->assertOk()->assertJsonPath('data.sent', true);
        $this->assertCount(1, $this->sent);
        $q = $this->link('/reset-password');
        $this->assertSame($user->email, $q['email']);
        $this->assertSame(64, strlen($q['token']));
        $this->assertNotSame($q['token'], DB::table('password_reset_tokens')->value('token'), 'stored hashed');

        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => str_repeat('x', 64), 'password' => 'new-password-1'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $q['token'], 'password' => 'new-password-1'])->assertOk();
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $q['token'], 'password' => 'again-password'])->assertStatus(422);
        $this->assertSame(0, $user->tokens()->count(), 'every session is signed out');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'new-password-1'])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at, 'using the inbox proves the address');
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertOk()->assertJsonPath('data.sent', true);
        $this->assertCount(0, $this->sent);
    }

    public function test_a_reset_link_expires_after_an_hour(): void
    {
        [$user] = $this->org();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $q = $this->link('/reset-password');
        Carbon::setTestNow(now()->addMinutes(61));
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $q['token'], 'password' => 'new-password-1'])->assertStatus(422);
        Carbon::setTestNow();
    }

    public function test_sign_up_sends_a_verification_link_that_confirms_the_address(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Ann', 'email' => 'ann@example.test', 'password' => 'secret-pass', 'organization' => 'Acme'])->assertCreated();
        $user = User::where('email', 'ann@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $q = $this->link('/verify-email');
        $this->assertSame($user->id, $q['id']);

        $this->postJson('/api/v1/auth/verify-email', ['id' => $user->id, 'token' => $q['token'].'x'])->assertStatus(422);
        $this->postJson('/api/v1/auth/verify-email', ['id' => $user->id, 'token' => $q['token']])->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_verification_link_dies_when_the_address_changes_or_it_expires(): void
    {
        [$user, , $token] = $this->org();
        $this->api($token)->postJson('/api/v1/me/verify-email')->assertOk()->assertJsonPath('data.sent', true);
        $q = $this->link('/verify-email');
        $user->forceFill(['email' => 'changed@example.test'])->save();
        $this->postJson('/api/v1/auth/verify-email', ['id' => $user->id, 'token' => $q['token']])->assertStatus(422);

        $user->forceFill(['email' => 'back@example.test'])->save();
        $this->api($token)->postJson('/api/v1/me/verify-email')->assertOk();
        $q = $this->link('/verify-email');
        Carbon::setTestNow(now()->addHours(49));
        $this->postJson('/api/v1/auth/verify-email', ['id' => $user->id, 'token' => $q['token']])->assertStatus(422);
        Carbon::setTestNow();

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->api($token)->postJson('/api/v1/me/verify-email')->assertOk()->assertJsonPath('data.verified', true);
    }
}
