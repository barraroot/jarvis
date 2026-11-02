<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('log_levels')]
class LogLevel extends LookupModel
{
    public const string DEBUG = 'debug';

    public const string INFO = 'info';

    public const string WARNING = 'warning';

    public const string ERROR = 'error';
}
