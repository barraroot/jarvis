<?php

namespace Tests\Feature;

use App\Exceptions\Handler;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\ProviderRegistry;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_registers_only_active_providers(): void
    {
        $active = AiProvider::factory()->create(['slug' => 'primary-openai', 'api_key' => 'sk-live-key', 'base_url' => 'https://api.openai.com/v1']);
        AiProvider::factory()->inactive()->create(['slug' => 'sleeping-anthropic', 'api_key' => 'sk-dormant-key']);
        AiProvider::factory()->create(['slug' => 'retired-groq', 'api_key' => 'sk-gone-key'])->delete();

        $registered = app(ProviderRegistry::class)->register();

        $this->assertArrayHasKey('primary-openai', $registered);
        $this->assertSame([
            'driver' => 'openai',
            'key' => 'sk-live-key',
            'url' => 'https://api.openai.com/v1',
        ], $registered['primary-openai']);
        $this->assertSame($registered['primary-openai'], config('ai.providers.primary-openai'));

        $this->assertArrayNotHasKey('sleeping-anthropic', $registered);
        $this->assertArrayNotHasKey('retired-groq', $registered);
        $this->assertNull(config('ai.providers.sleeping-anthropic'));
        $this->assertNull(config('ai.providers.retired-groq'));
        $this->assertSame('sk-live-key', $active->fresh()->api_key, 'The stored key must remain decryptable.');
    }

    public function test_deactivating_provider_removes_it_from_runtime_config(): void
    {
        $provider = AiProvider::factory()->create(['slug' => 'flaky-openai', 'api_key' => 'sk-flaky-key']);

        app(ProviderRegistry::class)->register();
        $this->assertNotNull(config('ai.providers.flaky-openai'));

        $provider->update(['is_active' => false]);

        // The provider list is cached for 60 s; dropping the cache simulates the next window.
        Cache::flush();

        app(ProviderRegistry::class)->register();
        $this->assertNull(config('ai.providers.flaky-openai'));
    }

    public function test_api_key_never_present_in_api_responses(): void
    {
        $provider = AiProvider::factory()->create(['slug' => 'secret-openai', 'api_key' => 'sk-super-secret-key-1234']);

        // Model serialization never exposes the key.
        $array = $provider->toArray();
        $json = json_encode($provider);

        $this->assertArrayNotHasKey('api_key', $array);
        $this->assertSame('****1234', $provider->masked_api_key);
        $this->assertStringNotContainsString('sk-super-secret-key-1234', $json);

        // The provider resource pages are fetched over HTTP and must render only the masked key.
        Filament::setCurrentPanel('admin');

        $this->actingAs(User::factory()->admin()->create());

        $index = $this->get('/admin/ai-providers');
        $index->assertOk();
        $this->assertStringNotContainsString('sk-super-secret-key-1234', $index->getContent());
        $this->assertStringContainsString('****1234', $index->getContent());

        $edit = $this->get('/admin/ai-providers/'.$provider->id.'/edit');
        $edit->assertOk();
        $this->assertStringNotContainsString('sk-super-secret-key-1234', $edit->getContent());
        $this->assertStringContainsString('****1234', $edit->getContent());
    }

    public function test_api_key_is_redacted_from_exception_context(): void
    {
        $exception = new class extends Exception
        {
            /**
             * @return array<string, mixed>
             */
            public function context(): array
            {
                return [
                    'provider' => [
                        'slug' => 'secret-openai',
                        'api_key' => 'sk-super-secret-key-1234',
                    ],
                ];
            }
        };

        $context = app(Handler::class)->contextForException($exception);

        $this->assertSame(['slug' => 'secret-openai'], $context['provider']);
        $this->assertStringNotContainsString('sk-super-secret-key-1234', json_encode($context));
    }
}
