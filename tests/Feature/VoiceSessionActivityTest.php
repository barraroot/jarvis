<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceSession;
use App\Services\VoiceTaskService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class VoiceSessionActivityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_is_active_respects_ttl_and_token_ceiling(): void
    {
        $settings = app(Settings::class);
        $settings->set('voice.session_token_ceiling', 1000);

        $user = User::factory()->create();

        $fresh = VoiceSession::factory()->for($user)->create(['total_tokens' => 999]);
        $this->assertTrue($fresh->isActive($settings));

        $withinTtl = VoiceSession::factory()->for($user)->create([
            'last_activity_at' => now()->subMinutes(VoiceSession::ACTIVE_TTL_MINUTES)->addSecond(),
        ]);
        $this->assertTrue($withinTtl->isActive($settings));

        $idle = VoiceSession::factory()->for($user)->inactive()->create();
        $this->assertFalse($idle->isActive($settings));

        $atCeiling = VoiceSession::factory()->for($user)->create(['total_tokens' => 1000]);
        $this->assertFalse($atCeiling->isActive($settings));

        $neverActive = VoiceSession::factory()->for($user)->create(['last_activity_at' => null]);
        $this->assertFalse($neverActive->isActive($settings));
    }

    public function test_inactive_session_is_rejected_with_pt_br_message_before_writing(): void
    {
        $user = User::factory()->create();
        $session = VoiceSession::factory()->for($user)->inactive()->create();

        try {
            app(VoiceTaskService::class)->start($user, $session, 'Busque algo', null);
            $this->fail('Expected a validation exception for the inactive session.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [__('voice.session_inactive')],
                $exception->errors()['voice_session_id'],
            );
        }

        $this->assertDatabaseCount('tasks', 0);
    }
}
