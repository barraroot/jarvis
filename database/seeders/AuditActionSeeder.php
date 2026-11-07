<?php

namespace Database\Seeders;

class AuditActionSeeder extends LookupTableSeeder
{
    protected function table(): string
    {
        return 'audit_actions';
    }

    /**
     * @return array<string, array{name: string, description?: string|null}>
     */
    protected function rows(): array
    {
        return [
            'login_succeeded' => ['name' => 'Login Succeeded'],
            'login_failed' => ['name' => 'Login Failed'],
            'logout' => ['name' => 'Logout'],
            'user_created' => ['name' => 'User Created'],
            'user_updated' => ['name' => 'User Updated'],
            'user_deactivated' => ['name' => 'User Deactivated'],
            'approval_approved' => ['name' => 'Approval Approved'],
            'approval_denied' => ['name' => 'Approval Denied'],
            'approval_expired' => ['name' => 'Approval Expired'],
            'tool_executed' => ['name' => 'Tool Executed'],
            'setting_updated' => ['name' => 'Setting Updated'],
            'agent_created' => ['name' => 'Agent Created'],
            'agent_updated' => ['name' => 'Agent Updated'],
            'agent_deleted' => ['name' => 'Agent Deleted'],
            'provider_created' => ['name' => 'Provider Created'],
            'provider_updated' => ['name' => 'Provider Updated'],
            'provider_deleted' => ['name' => 'Provider Deleted'],
            'model_created' => ['name' => 'Model Created'],
            'model_updated' => ['name' => 'Model Updated'],
            'model_deleted' => ['name' => 'Model Deleted'],
            'tool_created' => ['name' => 'Tool Created'],
            'tool_updated' => ['name' => 'Tool Updated'],
            'tool_deleted' => ['name' => 'Tool Deleted'],
            'connector_created' => ['name' => 'Connector Created'],
            'connector_updated' => ['name' => 'Connector Updated'],
            'connector_deleted' => ['name' => 'Connector Deleted'],
            'memory_deleted' => ['name' => 'Memory Deleted'],
            'task_cancelled' => ['name' => 'Task Cancelled'],
            'preference_updated' => ['name' => 'Preference Updated'],
            'whatsapp_consent_granted' => ['name' => 'WhatsApp Consent Granted'],
            'whatsapp_consent_revoked' => ['name' => 'WhatsApp Consent Revoked'],
            'whatsapp_session_provisioned' => ['name' => 'WhatsApp Session Provisioned'],
            'whatsapp_session_removed' => ['name' => 'WhatsApp Session Removed'],
            'whatsapp_session_suspended' => ['name' => 'WhatsApp Session Suspended'],
            'whatsapp_session_resumed' => ['name' => 'WhatsApp Session Resumed'],
            'whatsapp_message_sent' => ['name' => 'WhatsApp Message Sent'],
            'whatsapp_send_blocked' => ['name' => 'WhatsApp Send Blocked'],
            'whatsapp_schedule_created' => ['name' => 'WhatsApp Schedule Created'],
            'whatsapp_schedule_executed' => ['name' => 'WhatsApp Schedule Executed'],
            'whatsapp_schedule_cancelled' => ['name' => 'WhatsApp Schedule Cancelled'],
        ];
    }
}
