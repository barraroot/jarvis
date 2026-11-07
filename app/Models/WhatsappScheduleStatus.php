<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('whatsapp_schedule_statuses')]
class WhatsappScheduleStatus extends LookupModel
{
    public const string ACTIVE = 'active';

    public const string PAUSED = 'paused';

    public const string CANCELLED = 'cancelled';

    public const string COMPLETED = 'completed';
}
