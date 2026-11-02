<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('approval_statuses')]
class ApprovalStatus extends LookupModel
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string DENIED = 'denied';

    public const string EXPIRED = 'expired';
}
