<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('message_sources')]
class MessageSource extends LookupModel
{
    public const string TEXT = 'text';

    public const string VOICE = 'voice';
}
