<?php

namespace Tests\Feature;

use App\Models\McpServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class McpServerPolicyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_global_connector_visible_to_all_but_editable_only_by_admin(): void
    {
        $global = McpServer::factory()->global()->create();

        $regular = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $inactiveAdmin = User::factory()->admin()->inactive()->create();

        $this->assertTrue($regular->can('view', $global));
        $this->assertTrue($admin->can('view', $global));

        foreach (['update', 'delete', 'resync'] as $ability) {
            $this->assertFalse($regular->can($ability, $global), "Regular user must not {$ability} a global connector.");
            $this->assertTrue($admin->can($ability, $global), "Admin should {$ability} a global connector.");
            $this->assertFalse($inactiveAdmin->can($ability, $global), "Inactive admin must not {$ability} a global connector.");
        }
    }

    public function test_admin_cannot_manage_someone_elses_personal_connector(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $personal = McpServer::factory()->for($owner)->create();

        $this->assertTrue($owner->can('update', $personal));
        $this->assertFalse($admin->can('view', $personal));
        $this->assertFalse($admin->can('update', $personal));
        $this->assertFalse($admin->can('delete', $personal));
        $this->assertTrue($admin->can('create', McpServer::class));
    }
}
