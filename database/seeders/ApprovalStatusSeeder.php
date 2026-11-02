<?php

namespace Database\Seeders;

class ApprovalStatusSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'approval_statuses';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'pending' => ['name' => 'Pending'],
            'approved' => ['name' => 'Approved'],
            'denied' => ['name' => 'Denied'],
            'expired' => ['name' => 'Expired'],
        ];
    }
}
