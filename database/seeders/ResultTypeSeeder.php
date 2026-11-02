<?php

namespace Database\Seeders;

class ResultTypeSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'result_types';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'text' => ['name' => 'Text'],
            'list' => ['name' => 'List'],
            'table' => ['name' => 'Table'],
            'report' => ['name' => 'Report'],
            'search_results' => ['name' => 'Search Results'],
            'task_card' => ['name' => 'Task Card'],
            'data' => ['name' => 'Data'],
            'execution_status' => ['name' => 'Execution Status'],
            'document' => ['name' => 'Document'],
        ];
    }
}
