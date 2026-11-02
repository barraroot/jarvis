<?php

namespace Database\Seeders;

class TaskStatusSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'task_statuses';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'pending' => ['name' => 'Pending'],
            'queued' => ['name' => 'Queued'],
            'running' => ['name' => 'Running'],
            'waiting_approval' => ['name' => 'Waiting Approval'],
            'completed' => ['name' => 'Completed'],
            'failed' => ['name' => 'Failed'],
            'cancelled' => ['name' => 'Cancelled'],
        ];
    }
}
