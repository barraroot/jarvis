<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpaEntrypointTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_non_api_route_serves_spa_shell(): void
    {
        foreach (['/', '/login', '/conversations/42', '/settings', '/some/deep/client/path'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertViewIs('app')
                ->assertSee('<div id="app"', false);
        }
    }

    public function test_admin_route_is_not_captured_by_spa(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('<div id="app"', false);
    }

    public function test_backend_prefixes_are_not_captured_by_spa(): void
    {
        $this->getJson('/api/v1/unknown')->assertNotFound()->assertJsonMissingPath('view');
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->get('/up')->assertOk()->assertDontSee('<div id="app"', false);
    }
}
