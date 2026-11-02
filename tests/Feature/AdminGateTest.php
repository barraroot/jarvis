<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'admin'])->get('/_test/admin-only', fn () => response()->json(['ok' => true]));
    }

    public function test_inactive_admin_is_denied(): void
    {
        $inactiveAdmin = User::factory()->admin()->inactive()->create();

        $this->assertFalse(Gate::forUser($inactiveAdmin)->allows('admin'));
        $this->assertFalse($inactiveAdmin->isAdmin());

        $this->actingAs($inactiveAdmin)->getJson('/_test/admin-only')->assertForbidden();
    }

    public function test_active_admin_is_allowed_and_regular_user_is_denied(): void
    {
        $admin = User::factory()->admin()->create();
        $regular = User::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('admin'));
        $this->assertFalse(Gate::forUser($regular)->allows('admin'));

        $this->actingAs($admin)->getJson('/_test/admin-only')->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($regular)->getJson('/_test/admin-only')->assertForbidden();
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/_test/admin-only')->assertUnauthorized();
    }
}
