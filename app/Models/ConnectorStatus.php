<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('connector_statuses')]
class ConnectorStatus extends LookupModel
{
    public const string PENDING = 'pending';

    public const string CONNECTED = 'connected';

    public const string ERROR = 'error';

    public const string DISCONNECTED = 'disconnected';
}
