<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('whatsapp_outbound_statuses')]
class WhatsappOutboundStatus extends LookupModel
{
    public const string QUEUED = 'queued';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const string BLOCKED = 'blocked';
}
