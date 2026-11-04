<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Realtime voice
    |--------------------------------------------------------------------------
    |
    | User-facing texts of the OpenAI Realtime voice session (CT-01, CT-07,
    | CT-10) and the persona instructions sent to the provider.
    |
    */

    'errors' => [
        'realtime_unavailable' => 'A voz está indisponível: nenhum modelo Realtime da OpenAI ativo foi configurado. Use o chat por texto.',
        'provider_failed' => 'Não foi possível iniciar a sessão de voz agora: o provedor de voz não respondeu. Tente novamente em instantes.',
        'session_token_ceiling_exceeded' => 'O limite de uso desta sessão de voz foi atingido. Diga "Jarvis" novamente para iniciar uma nova sessão.',
        'wake_word_consent_required' => 'Para ativar a palavra de ativação, aceite o novo termo de consentimento: a detecção de "Jarvis" ocorre no navegador e, durante a janela de escuta, o áudio é enviado à OpenAI.',
    ],

    'task_cancelled_summary' => 'A tarefa foi cancelada antes de terminar.',

    'session_inactive' => 'A sessão de voz expirou. Diga "Jarvis" novamente para iniciar uma nova sessão.',

    'instructions' => <<<'TEXT'
        Você é o J.A.R.V.I.S., assistente pessoal do operador. Fale sempre em português do Brasil, com respostas curtas, naturais e objetivas.
        Quando o pedido exigir ferramentas, buscas, conectores ou trabalho de agentes, chame a função start_task com o pedido completo e confirme em uma frase curta que a tarefa foi iniciada, sem esperar o término. Use follow_up = true quando o pedido complementar a tarefa ou visualização anterior.
        Continue conversando normalmente enquanto as tarefas rodam em segundo plano; nunca cancele nem reinicie tarefas por conta própria.
        Quando receber o resultado de uma tarefa, narre um resumo breve. Se uma tarefa aguardar aprovação, avise o operador que há uma aprovação pendente.
        Indique emoções ou reações entre colchetes, por exemplo [risos], sem pronunciar os colchetes.
        TEXT,

    'tools' => [
        'start_task' => [
            'description' => 'Inicia uma tarefa em segundo plano executada pelos agentes do JARVIS (ferramentas, buscas e conectores).',
            'request' => 'Pedido completo do operador, em português.',
            'follow_up' => 'Verdadeiro quando o pedido complementa a tarefa ou visualização anterior.',
        ],
    ],
];
