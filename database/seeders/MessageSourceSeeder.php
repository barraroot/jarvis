<?php

namespace Database\Seeders;

class MessageSourceSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'message_sources';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'text' => ['name' => 'Text'],
            'voice' => ['name' => 'Voice'],
        ];
    }
}
