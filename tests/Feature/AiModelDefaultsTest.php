<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiModelDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private int $providerId;

    protected function setUp(): void
    {
        parent::setUp();

        $driverId = DB::table('ai_provider_drivers')->insertGetId([
            'name' => 'OpenAI',
            'slug' => 'openai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->providerId = DB::table('ai_providers')->insertGetId([
            'ai_provider_driver_id' => $driverId,
            'name' => 'OpenAI',
            'slug' => 'openai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_only_one_default_text_model_allowed(): void
    {
        $this->insertModel('gpt-4.1', ['is_default_text' => true]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function (): void {
            $this->insertModel('gpt-4.1-mini', ['is_default_text' => true]);
        });
    }

    public function test_only_one_default_embedding_model_allowed(): void
    {
        $this->insertModel('text-embedding-3-small', ['is_default_embedding' => true]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function (): void {
            $this->insertModel('text-embedding-3-large', ['is_default_embedding' => true]);
        });
    }

    public function test_multiple_non_default_models_allowed(): void
    {
        $this->insertModel('gpt-4.1');
        $this->insertModel('gpt-4.1-mini');
        $this->insertModel('gpt-4.1-nano', ['is_default_text' => true]);
        $this->insertModel('text-embedding-3-small', ['is_default_embedding' => true]);

        $this->assertSame(4, DB::table('ai_models')->count());
    }

    public function test_model_identifier_is_unique_per_provider(): void
    {
        $this->insertModel('gpt-4.1');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function (): void {
            $this->insertModel('gpt-4.1');
        });
    }

    public function test_capability_pivot_rejects_duplicated_pairs(): void
    {
        $modelId = $this->insertModel('gpt-4.1');
        $capabilityId = DB::table('ai_model_capabilities')->insertGetId([
            'name' => 'Text',
            'slug' => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_model_capability')->insert(['ai_model_id' => $modelId, 'ai_model_capability_id' => $capabilityId]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($modelId, $capabilityId): void {
            DB::table('ai_model_capability')->insert(['ai_model_id' => $modelId, 'ai_model_capability_id' => $capabilityId]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertModel(string $identifier, array $overrides = []): int
    {
        return DB::table('ai_models')->insertGetId(array_merge([
            'ai_provider_id' => $this->providerId,
            'name' => $identifier,
            'model_identifier' => $identifier,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
