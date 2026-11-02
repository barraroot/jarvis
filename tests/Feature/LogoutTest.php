<?php

namespace Tests\Feature;

use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private const array FRONTEND_HEADERS = ['Referer' => 'http://localhost:5173/'];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_logout_invalidates_session_and_is_audited(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'operator@example.com',
            'password' => 'password',
        ], self::FRONTEND_HEADERS)->assertOk();

        $this->assertAuthenticatedAs($user, 'web');

        $sessionId = session()->getId();
        $csrfToken = session()->token();

        $this->postJson('/api/v1/auth/logout', [], self::FRONTEND_HEADERS)->assertNoContent();

        $this->assertGuest('web');
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($csrfToken, session()->token());
        $this->assertFalse(session()->has('login_web_'.sha1(SessionGuard::class)));

        // Guards are cached per process; a real browser request starts from scratch.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/me', self::FRONTEND_HEADERS)->assertUnauthorized();

        $log = AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::LOGOUT))
            ->firstOrFail();

        $this->assertTrue($log->user->is($user));
        $this->assertTrue($log->subject->is($user));
        $this->assertSame('127.0.0.1', $log->ip_address);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout', [], self::FRONTEND_HEADERS)->assertUnauthorized();

        $this->assertDatabaseCount('audit_logs', 0);
    }
}
