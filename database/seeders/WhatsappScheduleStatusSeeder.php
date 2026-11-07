<?php

namespace Database\Seeders;

class WhatsappScheduleStatusSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'whatsapp_schedule_statuses';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'active' => ['name' => 'Active'],
            'paused' => ['name' => 'Paused'],
            'cancelled' => ['name' => 'Cancelled'],
            'completed' => ['name' => 'Completed'],
        ];
    }
}
