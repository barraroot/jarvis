<?php

namespace Database\Seeders;

class MessageRoleSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'message_roles';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'user' => ['name' => 'User'],
            'assistant' => ['name' => 'Assistant'],
            'tool' => ['name' => 'Tool'],
            'system' => ['name' => 'System'],
        ];
    }
}
