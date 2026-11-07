<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('notification_types')]
class NotificationType extends LookupModel
{
    public const string TASK_COMPLETED = 'task_completed';

    public const string TASK_FAILED = 'task_failed';

    public const string APPROVAL_PENDING = 'approval_pending';

    public const string CONNECTOR_ERROR = 'connector_error';

    public const string USAGE_LIMIT_REACHED = 'usage_limit_reached';

    public const string WHATSAPP_SEND_FAILED = 'whatsapp_send_failed';

    public const string WHATSAPP_SCHEDULE_RUN_FAILED = 'whatsapp_schedule_run_failed';

    public const string WHATSAPP_SESSION_SUSPENDED = 'whatsapp_session_suspended';
}
