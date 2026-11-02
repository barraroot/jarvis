<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('ai_model_capabilities')]
class AiModelCapability extends LookupModel
{
    public const string TEXT = 'text';

    public const string EMBEDDINGS = 'embeddings';
}
