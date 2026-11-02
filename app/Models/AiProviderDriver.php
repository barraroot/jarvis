<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('ai_provider_drivers')]
class AiProviderDriver extends LookupModel
{
    public const string OPENAI = 'openai';

    public const string ANTHROPIC = 'anthropic';

    public const string GEMINI = 'gemini';

    public const string GROQ = 'groq';

    public const string XAI = 'xai';

    public const string DEEPSEEK = 'deepseek';

    public const string MISTRAL = 'mistral';

    public const string OLLAMA = 'ollama';

    public const string AZURE = 'azure';

    public const string ELEVEN = 'eleven';

    public const string COHERE = 'cohere';

    public const string JINA = 'jina';

    public const string VOYAGEAI = 'voyageai';
}
