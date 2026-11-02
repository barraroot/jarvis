<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('task_statuses')]
class TaskStatus extends LookupModel
{
    public const string PENDING = 'pending';

    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string WAITING_APPROVAL = 'waiting_approval';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';
}
