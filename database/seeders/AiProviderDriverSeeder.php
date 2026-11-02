<?php

namespace Database\Seeders;

class AiProviderDriverSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'ai_provider_drivers';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'openai' => ['name' => 'OpenAI'],
            'anthropic' => ['name' => 'Anthropic'],
            'gemini' => ['name' => 'Gemini'],
            'groq' => ['name' => 'Groq'],
            'xai' => ['name' => 'xAI'],
            'deepseek' => ['name' => 'DeepSeek'],
            'mistral' => ['name' => 'Mistral'],
            'ollama' => ['name' => 'Ollama'],
            'azure' => ['name' => 'Azure OpenAI'],
            'eleven' => ['name' => 'ElevenLabs'],
            'cohere' => ['name' => 'Cohere'],
            'jina' => ['name' => 'Jina'],
            'voyageai' => ['name' => 'VoyageAI'],
        ];
    }
}
