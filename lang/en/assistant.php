<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Assistant messages
    |--------------------------------------------------------------------------
    |
    | User-facing texts emitted by the assistant pipeline (streaming failures,
    | usage limits). These are persisted as assistant messages and broadcast;
    | they must stay friendly and never carry stack traces or credentials.
    |
    */

    'errors' => [
        'all_providers_failed' => 'I could not answer right now: every AI provider is unavailable. Please try again shortly.',
        'usage_limit_exceeded' => 'The monthly AI usage limit has been reached. Please contact your administrator to keep using JARVIS.',
        'agent_failed' => 'I could not complete this request: the responsible agent failed. Details were recorded in the execution history.',
    ],
];
