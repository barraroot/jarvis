<?php

namespace Database\Seeders;

class LogLevelSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'log_levels';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'debug' => ['name' => 'Debug'],
            'info' => ['name' => 'Info'],
            'warning' => ['name' => 'Warning'],
            'error' => ['name' => 'Error'],
        ];
    }
}
