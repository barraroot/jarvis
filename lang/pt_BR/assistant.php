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
        'all_providers_failed' => 'Não consegui responder agora: todos os provedores de IA estão indisponíveis. Tente novamente em instantes.',
        'usage_limit_exceeded' => 'O limite mensal de uso de IA foi atingido. Fale com o administrador para continuar usando o JARVIS.',
    ],
];
