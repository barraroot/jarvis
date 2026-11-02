<?php

namespace Database\Seeders;

class ConnectorStatusSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'connector_statuses';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'pending' => ['name' => 'Pending'],
            'connected' => ['name' => 'Connected'],
            'error' => ['name' => 'Error'],
            'disconnected' => ['name' => 'Disconnected'],
        ];
    }
}
