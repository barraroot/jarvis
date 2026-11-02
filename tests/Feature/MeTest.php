<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_creates_default_preferences_on_first_call(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseCount('user_preferences', 0);

        $response = $this->actingAs($user)->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.preference.locale', 'pt-BR')
            ->assertJsonPath('data.preference.speech_locale', 'pt-BR')
            ->assertJsonPath('data.preference.speech_rate', 1)
            ->assertJsonPath('data.preference.spoken_responses_enabled', true)
            ->assertJsonPath('data.preference.wake_word_enabled', false)
            ->assertJsonPath('data.preference.silence_timeout_ms', 1500)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->assertDatabaseCount('user_preferences', 1);
        $this->assertDatabaseHas('user_preferences', ['user_id' => $user->id]);

        // A second call reuses the same row instead of creating another one.
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertDatabaseCount('user_preferences', 1);
    }

    public function test_me_returns_existing_preferences(): void
    {
        $preference = UserPreference::factory()->wakeWordEnabled()->create([
            'voice_name' => 'Google português do Brasil',
            'speech_rate' => 1.25,
        ]);

        $this->actingAs($preference->user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.preference.voice_name', 'Google português do Brasil')
            ->assertJsonPath('data.preference.speech_rate', 1.25)
            ->assertJsonPath('data.preference.wake_word_enabled', true);

        $this->assertDatabaseCount('user_preferences', 1);
    }
}
