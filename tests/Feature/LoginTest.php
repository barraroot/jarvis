<?php

namespace Tests\Feature;

use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private const string FRONTEND_REFERER = 'http://localhost:5173/login';

    protected function setUp(): void
    {
        parent::setUp();

        // Rate limiter hits live in the Redis cache, which RefreshDatabase does not reset.
        Cache::flush();
    }

    public function test_valid_credentials_authenticate_and_update_last_login(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.com']);

        $this->assertNull($user->last_login_at);

        $response = $this->login('operator@example.com', 'password');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'operator@example.com')
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->last_login_at);

        $this->getJson('/api/v1/auth/me', ['Referer' => self::FRONTEND_REFERER])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_invalid_credentials_return_422_with_generic_message(): void
    {
        User::factory()->create(['email' => 'operator@example.com']);

        $existing = $this->login('operator@example.com', 'wrong-password');
        $unknown = $this->login('nobody@example.com', 'wrong-password');

        $existing->assertUnprocessable()->assertJsonValidationErrors(['email' => __('auth.failed')]);
        $unknown->assertUnprocessable()->assertJsonValidationErrors(['email' => __('auth.failed')]);

        $this->assertSame($existing->json('errors'), $unknown->json('errors'));
        $this->assertGuest('web');
    }

    public function test_inactive_user_is_forbidden(): void
    {
        $user = User::factory()->inactive()->create(['email' => 'operator@example.com']);

        $this->login('operator@example.com', 'password')->assertForbidden();

        $this->assertGuest('web');
        $this->assertNull($user->fresh()->last_login_at);

        $log = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame(AuditAction::LOGIN_FAILED, $log->action->slug);
        $this->assertTrue($log->subject->is($user));
        $this->assertSame('inactive', $log->properties['reason']);
    }

    public function test_sixth_attempt_within_a_minute_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'operator@example.com']);

        foreach (range(1, 5) as $attempt) {
            $this->login('operator@example.com', 'wrong-password')->assertUnprocessable();
        }

        $this->login('operator@example.com', 'wrong-password')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        // A different e-mail from the same IP is not affected.
        $this->login('someone-else@example.com', 'wrong-password')->assertUnprocessable();
    }

    public function test_login_attempts_are_audited(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.com']);

        $this->login('operator@example.com', 'wrong-password')->assertUnprocessable();
        $this->login('operator@example.com', 'password')->assertOk();

        $failed = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::LOGIN_FAILED))->firstOrFail();
        $this->assertNull($failed->user_id);
        $this->assertSame('operator@example.com', $failed->properties['email']);
        $this->assertSame('invalid_credentials', $failed->properties['reason']);
        $this->assertArrayNotHasKey('password', $failed->properties);
        $this->assertSame('127.0.0.1', $failed->ip_address);

        $succeeded = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::LOGIN_SUCCEEDED))->firstOrFail();
        $this->assertTrue($succeeded->user->is($user));
        $this->assertTrue($succeeded->subject->is($user));
        $this->assertSame('127.0.0.1', $succeeded->ip_address);
    }

    private function login(string $email, string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ], ['Referer' => self::FRONTEND_REFERER]);
    }
}
