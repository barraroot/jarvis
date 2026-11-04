<?php

namespace Database\Seeders;

class AiModelCapabilitySeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'ai_model_capabilities';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'text' => ['name' => 'Text'],
            'embeddings' => ['name' => 'Embeddings'],
            'realtime' => ['name' => 'Realtime'],
        ];
    }
}
