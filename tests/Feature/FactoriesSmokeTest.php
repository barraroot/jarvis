<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AiModel;
use App\Models\AiModelCapability;
use App\Models\AiProvider;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\McpServer;
use App\Models\McpToolDefinition;
use App\Models\Memory;
use App\Models\MemoryEmbedding;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\TaskStep;
use App\Models\Tool;
use App\Models\ToolExecution;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\Visualization;
use App\Models\VisualizationStatus;
use App\Models\VoiceSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class FactoriesSmokeTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    /**
     * Every domain factory and its named states.
     *
     * @return array<class-string<Model>, list<string>>
     */
    private function factoryStates(): array
    {
        return [
            User::class => ['admin', 'inactive', 'withPreference', 'unverified'],
            UserPreference::class => ['wakeWordEnabled', 'silent'],
            AiProvider::class => ['inactive'],
            AiModel::class => ['defaultText', 'defaultEmbedding', 'embeddings', 'realtime', 'inactive'],
            Agent::class => ['default', 'inactive'],
            Tool::class => ['readOnly', 'write', 'sensitive', 'internal', 'rest', 'webhook', 'mcp', 'inactive'],
            McpServer::class => ['global', 'connected', 'errored', 'disconnected', 'inactive'],
            McpToolDefinition::class => ['unavailable'],
            Conversation::class => [],
            Message::class => ['fromUser', 'fromAssistant', 'voice', 'streaming', 'failed'],
            Memory::class => ['confirmed', 'hypothesis'],
            MemoryEmbedding::class => [],
            Task::class => ['pending', 'queued', 'running', 'waitingApproval', 'completed', 'failed', 'cancelled'],
            TaskStep::class => ['running', 'completed', 'failed'],
            TaskLog::class => ['error'],
            AgentExecution::class => ['running', 'completed', 'failed'],
            Approval::class => ['pending', 'approved', 'denied', 'expired'],
            ToolExecution::class => ['running', 'failed', 'timedOut'],
            Notification::class => ['read'],
            UsageRecord::class => ['failed'],
            AuditLog::class => ['system'],
            VoiceSession::class => ['inactive'],
            Visualization::class => ['updated', 'failed', 'closed'],
        ];
    }

    public function test_every_factory_creates_a_valid_record(): void
    {
        foreach ($this->factoryStates() as $modelClass => $states) {
            /** @var Factory<Model> $factory */
            $factory = $modelClass::factory();

            $record = $factory->create();

            $this->assertTrue($record->exists, "{$modelClass} factory did not persist.");
            $this->assertTrue($modelClass::query()->whereKey($record->getKey())->exists());

            foreach ($states as $state) {
                $stateRecord = $modelClass::factory()->{$state}()->create();

                $this->assertTrue($stateRecord->exists, "{$modelClass}::{$state}() did not persist.");
                $this->assertNotNull($stateRecord->fresh(), "{$modelClass}::{$state}() record not found after creation.");
            }
        }
    }

    public function test_child_execution_state_uses_parent_context(): void
    {
        $parent = AgentExecution::factory()->running()->create();
        $child = AgentExecution::factory()->childOf($parent)->create();

        $this->assertSame($parent->id, $child->parent_agent_execution_id);
        $this->assertSame(1, $child->depth);
        $this->assertSame($parent->user_id, $child->user_id);
    }

    public function test_user_states_and_preference(): void
    {
        $admin = User::factory()->admin()->withPreference()->create();
        $inactive = User::factory()->inactive()->admin()->create();

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($inactive->isAdmin());
        $this->assertTrue($admin->preference->user->is($admin));
        $this->assertSame('1.00', $admin->preference->speech_rate);
    }

    public function test_voice_session_and_visualization_factories(): void
    {
        $session = VoiceSession::factory()->create();
        $inactive = VoiceSession::factory()->inactive()->create();
        $visualization = Visualization::factory()->create();

        $this->assertSame($session->user_id, $session->conversation->user_id);
        $this->assertSame(0, $session->total_tokens);
        $this->assertTrue($session->model->hasCapability(AiModelCapability::REALTIME));
        $this->assertTrue(AiModel::query()->realtime()->whereKey($session->ai_model_id)->exists());
        $this->assertTrue($inactive->last_activity_at->lte(now()->subMinutes(31)));

        $this->travel(5)->minutes();
        $inactive->touchActivity();
        $this->assertTrue($inactive->fresh()->last_activity_at->gte(now()->subSecond()));

        $this->assertSame($visualization->user_id, $visualization->task->user_id);
        $this->assertSame(VisualizationStatus::SYNCING, $visualization->status->slug);
        $this->assertTrue($visualization->isOpen());
        $this->assertSame(1, $visualization->sections[0]['position']);
        $this->assertSame(1, Visualization::query()->open()->count());

        Visualization::factory()->closed()->create();
        $this->assertSame(1, Visualization::query()->open()->count());
    }

    public function test_wake_word_consent_requires_current_version(): void
    {
        $this->assertTrue(UserPreference::factory()->wakeWordEnabled()->create()->hasValidWakeWordConsent());
        $this->assertFalse(UserPreference::factory()->create()->hasValidWakeWordConsent());

        $legacy = UserPreference::factory()->wakeWordEnabled()->create(['wake_word_consent_version' => null]);
        $this->assertFalse($legacy->hasValidWakeWordConsent(), 'Consent without a version predates the current text.');

        $outdated = UserPreference::factory()->wakeWordEnabled()->create(['wake_word_consent_version' => 1]);
        $this->assertFalse($outdated->hasValidWakeWordConsent());

        $versionOnly = UserPreference::factory()->create(['wake_word_consent_version' => UserPreference::CURRENT_WAKE_WORD_CONSENT_VERSION]);
        $this->assertFalse($versionOnly->hasValidWakeWordConsent());
    }

    public function test_memory_embedding_factory_builds_1536_dimension_vector(): void
    {
        $embedding = MemoryEmbedding::factory()->create();

        $this->assertCount(1536, $embedding->fresh()->embedding->toArray());
    }
}
