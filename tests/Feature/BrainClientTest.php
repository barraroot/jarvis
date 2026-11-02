<?php

namespace Tests\Feature;

use App\Exceptions\AllProvidersFailedException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ExecutionStatus;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\BrainClient;
use App\Services\BrainRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class BrainClientTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        $this->provider = AiProvider::factory()->create();
    }

    public function test_falls_back_to_next_model_on_provider_error(): void
    {
        $primary = AiModel::factory()->for($this->provider, 'provider')->create(['model_identifier' => 'primary-model']);
        $fallback = AiModel::factory()->fallback(1)->for($this->provider, 'provider')->create(['model_identifier' => 'backup-model']);

        Ai::fakeAgent(AnonymousAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): mixed {
            if ($model === 'primary-model') {
                throw new RuntimeException('Provider exploded.');
            }

            return new TextResponse('Fallback works', new TextUsage(60, 30), new Meta($provider->name(), $model));
        });

        $chunks = [];

        $response = app(BrainClient::class)->stream(
            new BrainRequest(User::factory()->create(), $primary, 'Hello'),
            function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        $this->assertTrue($response->model->is($fallback));
        $this->assertSame('Fallback works', $response->text);
        $this->assertSame(60, $response->inputTokens);
        $this->assertSame(30, $response->outputTokens);
        $this->assertSame(['Fallback', ' works'], $chunks);

        $this->assertSame(2, UsageRecord::query()->count());
        $this->assertDatabaseHas('usage_records', [
            'ai_model_id' => $primary->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::FAILED),
        ]);
        $this->assertDatabaseHas('usage_records', [
            'ai_model_id' => $fallback->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
            'input_tokens' => 60,
            'output_tokens' => 30,
        ]);
    }

    public function test_records_usage_for_each_attempt(): void
    {
        $primary = AiModel::factory()->for($this->provider, 'provider')->create(['model_identifier' => 'primary-model']);
        $rateLimited = AiModel::factory()->fallback(1)->for($this->provider, 'provider')->create(['model_identifier' => 'limited-model']);
        $timedOut = AiModel::factory()->fallback(2)->for($this->provider, 'provider')->create(['model_identifier' => 'slow-model']);
        $winner = AiModel::factory()->fallback(3)->for($this->provider, 'provider')->create(['model_identifier' => 'winner-model']);

        Ai::fakeAgent(AnonymousAgent::class, function (string $prompt, mixed $attachments, mixed $provider, string $model): mixed {
            return match ($model) {
                'primary-model' => throw new RateLimitedException('429 Too Many Requests'),
                'limited-model' => throw new RateLimitedException('429 Too Many Requests'),
                'slow-model' => throw new RuntimeException('Connection timed out after 60 seconds'),
                default => new TextResponse('Winner', new TextUsage(10, 5), new Meta($provider->name(), $model)),
            };
        });

        $response = app(BrainClient::class)->stream(
            new BrainRequest(User::factory()->create(), $primary, 'Hello'),
            fn (string $chunk): null => null,
        );

        $this->assertTrue($response->model->is($winner));
        $this->assertSame(4, UsageRecord::query()->count());

        $this->assertDatabaseHas('usage_records', [
            'ai_model_id' => $primary->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::RATE_LIMITED),
        ]);
        $this->assertDatabaseHas('usage_records', [
            'ai_model_id' => $rateLimited->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::RATE_LIMITED),
        ]);
        $this->assertDatabaseHas('usage_records', [
            'ai_model_id' => $timedOut->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::TIMED_OUT),
        ]);
        $this->assertDatabaseHas('usage_records', [
            'ai_model_id' => $winner->id,
            'execution_status_id' => ExecutionStatus::idFor(ExecutionStatus::COMPLETED),
        ]);
    }

    public function test_throws_all_providers_failed_after_chain_exhausted(): void
    {
        $primary = AiModel::factory()->for($this->provider, 'provider')->create(['model_identifier' => 'primary-model']);
        $fallback = AiModel::factory()->fallback(1)->for($this->provider, 'provider')->create(['model_identifier' => 'backup-model']);

        Ai::fakeAgent(AnonymousAgent::class, fn (): never => throw new RuntimeException('Down'));

        try {
            app(BrainClient::class)->stream(
                new BrainRequest(User::factory()->create(), $primary, 'Hello'),
                fn (string $chunk): null => null,
            );

            $this->fail('Expected AllProvidersFailedException was not thrown.');
        } catch (AllProvidersFailedException $exception) {
            $this->assertSame(['primary-model', 'backup-model'], $exception->attemptedModels);
            $this->assertStringContainsString('primary-model, backup-model', $exception->getMessage());
            $this->assertStringNotContainsString('sk-', $exception->getMessage());
            $this->assertStringNotContainsString($this->provider->api_key ?? '', $exception->getMessage());
        }

        $this->assertSame(2, UsageRecord::query()->count());
    }

    public function test_streams_chunks_in_order(): void
    {
        $model = AiModel::factory()->for($this->provider, 'provider')->create(['model_identifier' => 'ok-model']);

        Ai::fakeAgent(AnonymousAgent::class, fn (string $prompt, mixed $attachments, mixed $provider, string $m): mixed => new TextResponse('alpha beta gamma', new TextUsage(3, 3), new Meta($provider->name(), $m)));

        $chunks = [];

        $response = app(BrainClient::class)->stream(
            new BrainRequest(User::factory()->create(), $model, 'Hello', [
                ['role' => 'user', 'content' => 'Earlier message'],
            ]),
            function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        $this->assertSame(['alpha', ' beta', ' gamma'], $chunks);
        $this->assertSame('alpha beta gamma', $response->text);
        $this->assertTrue($response->model->is($model));
    }
}
