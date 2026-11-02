<?php

namespace Database\Seeders;

class MemoryCategorySeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'memory_categories';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'preference' => ['name' => 'Preference'],
            'project' => ['name' => 'Project'],
            'context' => ['name' => 'Context'],
            'learning' => ['name' => 'Learning'],
            'fact' => ['name' => 'Fact'],
        ];
    }
}
