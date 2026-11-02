<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InactiveUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_user_loses_access_on_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();

        $user->update(['is_active' => false]);

        // Session is still "valid" (the user is still resolved by the guard), yet access is denied.
        $this->actingAs($user->fresh())->getJson('/api/v1/auth/me')->assertForbidden();
        $this->actingAs($user->fresh())->postJson('/api/v1/auth/logout')->assertForbidden();
    }

    public function test_deactivation_deletes_sessions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->insertSession('session-a', $user);
        $this->insertSession('session-b', $user);
        $this->insertSession('session-other', $otherUser);
        $this->insertSession('session-guest', null);

        $user->update(['is_active' => false]);

        $this->assertDatabaseMissing('sessions', ['id' => 'session-a']);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-b']);
        $this->assertDatabaseHas('sessions', ['id' => 'session-other']);
        $this->assertDatabaseHas('sessions', ['id' => 'session-guest']);
    }

    public function test_other_updates_keep_sessions(): void
    {
        $user = User::factory()->create();
        $this->insertSession('session-a', $user);

        $user->update(['name' => 'Renamed']);
        $this->assertDatabaseHas('sessions', ['id' => 'session-a']);

        $user->update(['is_active' => true]);
        $this->assertDatabaseHas('sessions', ['id' => 'session-a']);
    }

    private function insertSession(string $id, ?User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user?->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);
    }
}
