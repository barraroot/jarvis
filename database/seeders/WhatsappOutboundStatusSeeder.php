<?php

namespace Database\Seeders;

class WhatsappOutboundStatusSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'whatsapp_outbound_statuses';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'queued' => ['name' => 'Queued'],
            'sent' => ['name' => 'Sent'],
            'failed' => ['name' => 'Failed'],
            'blocked' => ['name' => 'Blocked'],
        ];
    }
}
