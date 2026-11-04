<?php

namespace Database\Seeders;

class VisualizationStatusSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'visualization_statuses';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'syncing' => ['name' => 'Syncing'],
            'updated' => ['name' => 'Updated'],
            'failed' => ['name' => 'Failed'],
        ];
    }
}
