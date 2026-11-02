<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AiModel;
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
            AiModel::class => ['defaultText', 'defaultEmbedding', 'embeddings', 'inactive'],
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

    public function test_memory_embedding_factory_builds_1536_dimension_vector(): void
    {
        $embedding = MemoryEmbedding::factory()->create();

        $this->assertCount(1536, $embedding->fresh()->embedding->toArray());
    }
}
