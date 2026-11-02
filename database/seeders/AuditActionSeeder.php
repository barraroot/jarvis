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
        ];
    }
}
