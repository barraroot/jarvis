<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('memory_statuses')]
class MemoryStatus extends LookupModel
{
    public const string CONFIRMED = 'confirmed';

    public const string HYPOTHESIS = 'hypothesis';
}
