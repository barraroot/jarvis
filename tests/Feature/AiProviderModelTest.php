<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AiProviderDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AiProviderModelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_api_key_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $provider = AiProvider::factory()->create(['api_key' => 'sk-live-ABCDEFGH1234']);

        $stored = DB::table('ai_providers')->where('id', $provider->id)->value('api_key');

        $this->assertNotSame('sk-live-ABCDEFGH1234', $stored);
        $this->assertStringNotContainsString('ABCDEFGH1234', $stored);
        $this->assertSame('sk-live-ABCDEFGH1234', Crypt::decryptString($stored));

        $this->assertSame('sk-live-ABCDEFGH1234', $provider->fresh()->api_key);

        $serialized = $provider->fresh()->toArray();
        $this->assertArrayNotHasKey('api_key', $serialized);
        $this->assertStringNotContainsString('ABCDEFGH1234', json_encode($serialized));
    }

    public function test_masked_api_key_exposes_only_last_four_characters(): void
    {
        $provider = AiProvider::factory()->create(['api_key' => 'sk-live-ABCDEFGH1234']);

        $this->assertSame('****1234', $provider->masked_api_key);

        $provider->api_key = null;
        $this->assertNull($provider->masked_api_key);
    }

    public function test_driver_relation_and_active_scope(): void
    {
        $active = AiProvider::factory()->driver(AiProviderDriver::ANTHROPIC)->create();
        AiProvider::factory()->inactive()->create();

        $this->assertSame(AiProviderDriver::ANTHROPIC, $active->driver->slug);
        $this->assertSame([$active->id], AiProvider::active()->pluck('id')->all());
    }
}
