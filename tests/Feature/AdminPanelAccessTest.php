<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_gets_403(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)->get('/admin')->assertForbidden();

        $this->assertFalse($operator->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_inactive_admin_gets_403(): void
    {
        $inactiveAdmin = User::factory()->admin()->inactive()->create();

        $this->actingAs($inactiveAdmin)->get('/admin')->assertForbidden();
    }

    public function test_guest_is_redirected_to_filament_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');

        $this->get('/admin/login')->assertOk();
    }

    public function test_admin_can_open_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('JARVIS')
            ->assertSee('--gray-950: #050a12', false)
            ->assertSee('--primary-500:oklch(', false);

        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_panel_uses_web_guard_and_hud_theme(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame('admin', $panel->getPath());
        $this->assertSame('web', $panel->getAuthGuard());
        $this->assertTrue($panel->hasDarkMode());
        $this->assertSame('dark', $panel->getDefaultThemeMode()->value);

        $labels = array_map(
            fn ($group) => $group->getLabel(),
            $panel->getNavigationGroups(),
        );

        $this->assertSame(['IA', 'Operação', 'Sistema'], array_values($labels));
    }
}
