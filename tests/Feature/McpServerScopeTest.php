<?php

namespace Tests\Feature;

use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class McpServerScopeTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_for_user_scope_returns_global_and_own_only(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $global = McpServer::factory()->global()->create();
        $mine = McpServer::factory()->for($me)->create();
        McpServer::factory()->for($other)->create();

        $this->assertEqualsCanonicalizing([$global->id, $mine->id], McpServer::forUser($me)->pluck('id')->all());
        $this->assertSame([$global->id], McpServer::global()->pluck('id')->all());
        $this->assertTrue($global->isGlobal());
        $this->assertFalse($mine->isGlobal());
        $this->assertTrue($me->mcpServers->contains($mine));
    }

    public function test_status_states_and_credential_protection(): void
    {
        $connected = McpServer::factory()->connected()->create(['credential' => 'token-ABCD']);
        $errored = McpServer::factory()->errored()->create();
        McpServer::factory()->inactive()->create();

        $this->assertSame(ConnectorStatus::CONNECTED, $connected->status->slug);
        $this->assertSame(ConnectorStatus::ERROR, $errored->status->slug);
        $this->assertSame('Connection refused', $errored->last_error);
        $this->assertSame(2, McpServer::active()->count());

        $this->assertStringNotContainsString('token-ABCD', DB::table('mcp_servers')->where('id', $connected->id)->value('credential'));
        $this->assertArrayNotHasKey('credential', $connected->toArray());
        $this->assertSame('token-ABCD', $connected->fresh()->credential);
    }
}
