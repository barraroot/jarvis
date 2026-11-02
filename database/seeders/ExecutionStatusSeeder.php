<?php

namespace Database\Seeders;

class ExecutionStatusSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'execution_statuses';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'pending' => ['name' => 'Pending'],
            'running' => ['name' => 'Running'],
            'completed' => ['name' => 'Completed'],
            'failed' => ['name' => 'Failed'],
            'timed_out' => ['name' => 'Timed Out'],
            'cancelled' => ['name' => 'Cancelled'],
            'rate_limited' => ['name' => 'Rate Limited'],
        ];
    }
}
