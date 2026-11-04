<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('visualization_statuses')]
class VisualizationStatus extends LookupModel
{
    public const string SYNCING = 'syncing';

    public const string UPDATED = 'updated';

    public const string FAILED = 'failed';
}
