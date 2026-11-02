<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('memory_categories')]
class MemoryCategory extends LookupModel
{
    public const string PREFERENCE = 'preference';

    public const string PROJECT = 'project';

    public const string CONTEXT = 'context';

    public const string LEARNING = 'learning';

    public const string FACT = 'fact';
}
