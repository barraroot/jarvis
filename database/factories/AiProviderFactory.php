<?php

namespace Database\Factories;

use App\Models\AiProvider;
use App\Models\AiProviderDriver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiProvider>
 */
class AiProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'ai_provider_driver_id' => AiProviderDriver::idFor(AiProviderDriver::OPENAI),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'api_key' => 'sk-'.Str::random(40),
            'base_url' => null,
            'is_active' => true,
            'last_tested_at' => null,
            'last_test_error' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function driver(string $driverSlug): static
    {
        return $this->state(fn (array $attributes): array => [
            'ai_provider_driver_id' => AiProviderDriver::idFor($driverSlug),
        ]);
    }
}
