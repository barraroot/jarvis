<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UserChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_subscribe_to_own_channel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->authorizeChannel("private-user.{$user->id}")
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_user_cannot_subscribe_to_another_users_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->authorizeChannel("private-user.{$other->id}")
            ->assertForbidden();
    }

    public function test_guest_cannot_subscribe_to_user_channel(): void
    {
        $user = User::factory()->create();

        $this->authorizeChannel("private-user.{$user->id}")->assertUnauthorized();
    }

    public function test_inactive_user_cannot_subscribe_to_own_channel(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)
            ->authorizeChannel("private-user.{$user->id}")
            ->assertForbidden();
    }

    private function authorizeChannel(string $channel): TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }
}
