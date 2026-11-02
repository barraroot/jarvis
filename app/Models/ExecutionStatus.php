<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('execution_statuses')]
class ExecutionStatus extends LookupModel
{
    public const string PENDING = 'pending';

    public const string RUNNING = 'running';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    public const string TIMED_OUT = 'timed_out';

    public const string CANCELLED = 'cancelled';

    public const string RATE_LIMITED = 'rate_limited';
}
