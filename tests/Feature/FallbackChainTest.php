<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\FallbackChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class FallbackChainTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_chain_excludes_inactive_providers_and_models_without_order(): void
    {
        $provider = AiProvider::factory()->create();
        $inactiveProvider = AiProvider::factory()->inactive()->create();

        $primary = AiModel::factory()->for($provider, 'provider')->create();
        $firstFallback = AiModel::factory()->fallback(1)->for($provider, 'provider')->create();
        $ofInactiveProvider = AiModel::factory()->fallback(2)->for($inactiveProvider, 'provider')->create();
        $withoutOrder = AiModel::factory()->for($provider, 'provider')->create();
        $secondFallback = AiModel::factory()->fallback(3)->for($provider, 'provider')->create();
        AiModel::factory()->inactive()->fallback(4)->for($provider, 'provider')->create();

        $chain = app(FallbackChain::class)->for($primary);

        $this->assertSame(
            [$primary->id, $firstFallback->id, $secondFallback->id],
            array_map(fn (AiModel $model): int => $model->id, $chain),
        );
        $this->assertNotContains($ofInactiveProvider->id, array_map(fn (AiModel $model): int => $model->id, $chain));
        $this->assertNotContains($withoutOrder->id, array_map(fn (AiModel $model): int => $model->id, $chain));
    }

    public function test_primary_is_not_repeated_when_it_has_a_fallback_order(): void
    {
        $provider = AiProvider::factory()->create();

        $primary = AiModel::factory()->fallback(1)->for($provider, 'provider')->create();
        $secondary = AiModel::factory()->fallback(2)->for($provider, 'provider')->create();

        $chain = app(FallbackChain::class)->for($primary);

        $this->assertCount(2, $chain);
        $this->assertTrue($chain[0]->is($primary));
        $this->assertTrue($chain[1]->is($secondary));
    }

    public function test_chain_is_ordered_by_fallback_order(): void
    {
        $provider = AiProvider::factory()->create();

        $primary = AiModel::factory()->for($provider, 'provider')->create();
        $second = AiModel::factory()->fallback(2)->for($provider, 'provider')->create();
        $first = AiModel::factory()->fallback(1)->for($provider, 'provider')->create();

        $chain = app(FallbackChain::class)->for($primary);

        $this->assertSame(
            [$primary->id, $first->id, $second->id],
            array_map(fn (AiModel $model): int => $model->id, $chain),
        );
    }
}
