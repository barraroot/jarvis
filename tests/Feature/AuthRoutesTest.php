<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuthRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_route_does_not_exist(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'a-very-long-password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }

    public function test_protected_route_returns_401_when_unauthenticated(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_csrf_cookie_endpoint_sets_xsrf_token(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_cors_allows_credentials_for_the_frontend_origin(): void
    {
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertContains('http://localhost:5173', config('cors.allowed_origins'));
        $this->assertContains('api/*', config('cors.paths'));
        $this->assertContains('localhost:5173', config('sanctum.stateful'));
    }

    public function test_user_resource_never_exposes_credentials(): void
    {
        $user = User::factory()->admin()->create();

        $payload = UserResource::make($user)->resolve(Request::create('/api/v1/auth/me'));

        $this->assertSame($user->id, $payload['id']);
        $this->assertSame($user->name, $payload['name']);
        $this->assertSame($user->email, $payload['email']);
        $this->assertTrue($payload['is_admin']);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
    }
}
