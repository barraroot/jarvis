<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_show_returns_preferences_creating_defaults_on_demand(): void
    {
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $this->user->id]);

        $response = $this->getJson('/api/v1/preferences');

        $response->assertOk()
            ->assertJsonPath('data.locale', 'pt-BR')
            ->assertJsonPath('data.speech_locale', 'pt-BR')
            ->assertJsonPath('data.voice_name', null)
            ->assertJsonPath('data.speech_rate', 1)
            ->assertJsonPath('data.spoken_responses_enabled', true)
            ->assertJsonPath('data.wake_word_enabled', false)
            ->assertJsonPath('data.wake_word_consented_at', null)
            ->assertJsonPath('data.silence_timeout_ms', 1500);

        $this->assertDatabaseHas('user_preferences', ['user_id' => $this->user->id]);
    }

    public function test_update_persists_locale_and_voice_preferences(): void
    {
        $response = $this->putJson('/api/v1/preferences', [
            'locale' => 'en-US',
            'speech_locale' => 'en-US',
            'voice_name' => 'Google US English',
            'speech_rate' => 1.4,
            'spoken_responses_enabled' => false,
            'silence_timeout_ms' => 800,
            'extra' => ['favorite_lake' => 'Superior'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.locale', 'en-US')
            ->assertJsonPath('data.speech_locale', 'en-US')
            ->assertJsonPath('data.voice_name', 'Google US English')
            ->assertJsonPath('data.speech_rate', 1.4)
            ->assertJsonPath('data.spoken_responses_enabled', false)
            ->assertJsonPath('data.silence_timeout_ms', 800)
            ->assertJsonPath('data.extra.favorite_lake', 'Superior');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $this->user->id,
            'locale' => 'en-US',
            'voice_name' => 'Google US English',
            'speech_rate' => 1.40,
            'spoken_responses_enabled' => false,
            'silence_timeout_ms' => 800,
        ]);
    }

    public function test_speech_rate_outside_range_is_rejected(): void
    {
        $response = $this->putJson('/api/v1/preferences', ['speech_rate' => 0.4]);

        $response->assertUnprocessable()->assertJsonValidationErrors('speech_rate');

        $response = $this->putJson('/api/v1/preferences', ['speech_rate' => 2.1]);

        $response->assertUnprocessable()->assertJsonValidationErrors('speech_rate');
    }

    public function test_voice_name_is_rejected_above_120_characters(): void
    {
        $response = $this->putJson('/api/v1/preferences', ['voice_name' => str_repeat('v', 121)]);

        $response->assertUnprocessable()->assertJsonValidationErrors('voice_name');
    }

    public function test_silence_timeout_must_stay_between_500_and_5000_ms(): void
    {
        $response = $this->putJson('/api/v1/preferences', ['silence_timeout_ms' => 499]);

        $response->assertUnprocessable()->assertJsonValidationErrors('silence_timeout_ms');

        $response = $this->putJson('/api/v1/preferences', ['silence_timeout_ms' => 5001]);

        $response->assertUnprocessable()->assertJsonValidationErrors('silence_timeout_ms');

        $this->putJson('/api/v1/preferences', ['silence_timeout_ms' => 5000])->assertOk();
    }

    public function test_locale_must_use_the_region_format(): void
    {
        $response = $this->putJson('/api/v1/preferences', ['locale' => 'ptbr']);

        $response->assertUnprocessable()->assertJsonValidationErrors('locale');

        $this->putJson('/api/v1/preferences', ['speech_locale' => 'pt-BR'])->assertOk();
    }

    public function test_extra_must_be_an_array(): void
    {
        $response = $this->putJson('/api/v1/preferences', ['extra' => 'not-an-array']);

        $response->assertUnprocessable()->assertJsonValidationErrors('extra');
    }

    public function test_enabling_wake_word_requires_consent_and_records_timestamp(): void
    {
        $response = $this->putJson('/api/v1/preferences', ['wake_word_enabled' => true]);

        $response->assertUnprocessable()->assertJsonValidationErrors('wake_word_consent');
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $this->user->id,
            'wake_word_enabled' => false,
            'wake_word_consented_at' => null,
        ]);

        $this->travelTo(now()->addMinutes(3));

        $response = $this->putJson('/api/v1/preferences', [
            'wake_word_enabled' => true,
            'wake_word_consent' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.wake_word_enabled', true)
            ->assertJsonPath('data.wake_word_consented_at', fn (mixed $value): bool => $value !== null);

        $preferences = DB::table('user_preferences')->where('user_id', $this->user->id)->first();

        $this->assertTrue((bool) $preferences->wake_word_enabled);
        $this->assertNotNull($preferences->wake_word_consented_at);
    }

    public function test_consent_timestamp_is_recorded_only_once(): void
    {
        $this->travelTo($firstAcceptance = now());

        $this->putJson('/api/v1/preferences', [
            'wake_word_enabled' => true,
            'wake_word_consent' => true,
        ])->assertOk();

        $this->travelTo(now()->addDays(2));

        $this->putJson('/api/v1/preferences', [
            'wake_word_enabled' => true,
            'wake_word_consent' => true,
        ])->assertOk();

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $this->user->id,
            'wake_word_consented_at' => $firstAcceptance->toDateTimeString(),
        ]);
    }

    public function test_disabling_wake_word_keeps_the_consent(): void
    {
        $this->putJson('/api/v1/preferences', [
            'wake_word_enabled' => true,
            'wake_word_consent' => true,
        ])->assertOk();

        $consentedAt = DB::table('user_preferences')->where('user_id', $this->user->id)->value('wake_word_consented_at');

        $response = $this->putJson('/api/v1/preferences', ['wake_word_enabled' => false]);

        $response->assertOk()->assertJsonPath('data.wake_word_enabled', false);

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $this->user->id,
            'wake_word_enabled' => false,
            'wake_word_consented_at' => $consentedAt,
        ]);

        // Re-enabling still requires the consent flag on that payload (US-4.4).
        $this->putJson('/api/v1/preferences', ['wake_word_enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wake_word_consent');
    }

    public function test_preferences_are_per_user(): void
    {
        $this->putJson('/api/v1/preferences', [
            'locale' => 'en-US',
            'speech_locale' => 'en-US',
            'speech_rate' => 1.8,
        ])->assertOk();

        $other = User::factory()->create();
        $this->actingAs($other);

        $response = $this->getJson('/api/v1/preferences');

        $response->assertOk()
            ->assertJsonPath('data.locale', 'pt-BR')
            ->assertJsonPath('data.speech_locale', 'pt-BR')
            ->assertJsonPath('data.speech_rate', 1);

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $this->user->id,
            'locale' => 'en-US',
            'speech_rate' => 1.80,
        ]);
        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $other->id,
            'locale' => 'pt-BR',
        ]);
    }

    public function test_guests_cannot_access_preferences(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/preferences')->assertUnauthorized();
        $this->putJson('/api/v1/preferences', ['locale' => 'en-US'])->assertUnauthorized();
    }
}
