<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('message_roles')]
class MessageRole extends LookupModel
{
    public const string USER = 'user';

    public const string ASSISTANT = 'assistant';

    public const string TOOL = 'tool';

    public const string SYSTEM = 'system';
}
