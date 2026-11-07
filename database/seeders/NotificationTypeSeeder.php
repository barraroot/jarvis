<?php

namespace Database\Seeders;

class NotificationTypeSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'notification_types';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'task_completed' => ['name' => 'Task Completed'],
            'task_failed' => ['name' => 'Task Failed'],
            'approval_pending' => ['name' => 'Approval Pending'],
            'connector_error' => ['name' => 'Connector Error'],
            'usage_limit_reached' => ['name' => 'Usage Limit Reached'],
            'whatsapp_send_failed' => ['name' => 'WhatsApp Send Failed'],
            'whatsapp_schedule_run_failed' => ['name' => 'WhatsApp Schedule Run Failed'],
            'whatsapp_session_suspended' => ['name' => 'WhatsApp Session Suspended'],
        ];
    }
}
