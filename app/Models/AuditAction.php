<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('audit_actions')]
class AuditAction extends LookupModel
{
    public const string LOGIN_SUCCEEDED = 'login_succeeded';

    public const string LOGIN_FAILED = 'login_failed';

    public const string LOGOUT = 'logout';

    public const string USER_CREATED = 'user_created';

    public const string USER_UPDATED = 'user_updated';

    public const string USER_DEACTIVATED = 'user_deactivated';

    public const string APPROVAL_APPROVED = 'approval_approved';

    public const string APPROVAL_DENIED = 'approval_denied';

    public const string APPROVAL_EXPIRED = 'approval_expired';

    public const string TOOL_EXECUTED = 'tool_executed';

    public const string SETTING_UPDATED = 'setting_updated';

    public const string AGENT_CREATED = 'agent_created';

    public const string AGENT_UPDATED = 'agent_updated';

    public const string AGENT_DELETED = 'agent_deleted';

    public const string PROVIDER_CREATED = 'provider_created';

    public const string PROVIDER_UPDATED = 'provider_updated';

    public const string PROVIDER_DELETED = 'provider_deleted';

    public const string MODEL_CREATED = 'model_created';

    public const string MODEL_UPDATED = 'model_updated';

    public const string MODEL_DELETED = 'model_deleted';

    public const string TOOL_CREATED = 'tool_created';

    public const string TOOL_UPDATED = 'tool_updated';

    public const string TOOL_DELETED = 'tool_deleted';

    public const string CONNECTOR_CREATED = 'connector_created';

    public const string CONNECTOR_UPDATED = 'connector_updated';

    public const string CONNECTOR_DELETED = 'connector_deleted';

    public const string MEMORY_DELETED = 'memory_deleted';
}
