<?php

namespace Database\Seeders;

class MemoryStatusSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'memory_statuses';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'confirmed' => ['name' => 'Confirmed'],
            'hypothesis' => ['name' => 'Hypothesis'],
        ];
    }
}
