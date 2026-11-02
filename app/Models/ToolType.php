<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('tool_types')]
class ToolType extends LookupModel
{
    public const string INTERNAL = 'internal';

    public const string MCP = 'mcp';

    public const string REST = 'rest';

    public const string WEBHOOK = 'webhook';
}
