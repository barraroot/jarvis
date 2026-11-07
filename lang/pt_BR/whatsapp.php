<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp connector
    |--------------------------------------------------------------------------
    |
    | User-facing texts of the WhatsApp connector: tool error codes (CT-31),
    | consent scopes, API errors and settings validation.
    |
    */

    'errors' => [
        'whatsapp_consent_required' => 'O consentimento do WhatsApp está ausente, revogado, desatualizado ou não inclui os escopos exigidos.',
        'whatsapp_not_connected' => 'O WhatsApp não está conectado. Conecte a sessão pelo painel do WhatsApp e tente novamente.',
        'whatsapp_session_suspended' => 'A sessão do WhatsApp foi suspensa por um administrador.',
        'whatsapp_rate_limited' => 'Limite de envios por minuto do WhatsApp atingido.',
        'whatsapp_recipient_rate_limited' => 'Limite de envios por hora para este destinatário atingido.',
        'whatsapp_daily_cap_reached' => 'Teto diário de envios do WhatsApp atingido. Novos envios só a partir de 00:00 (horário de Brasília).',
        'whatsapp_mass_send_blocked' => 'Envio bloqueado: limite de destinatários fora dos contatos atingido na janela atual (proteção contra envio em massa).',
        'whatsapp_kill_switch_active' => 'O envio pelo WhatsApp está temporariamente desativado pelo administrador.',
        'whatsapp_media_invalid' => 'A mídia informada é inválida: use uma URL https pública dentro do limite de tamanho do tipo.',
        'whatsapp_upstream_error' => 'O serviço do WhatsApp respondeu com erro ou não respondeu a tempo.',
    ],

    'missing_scopes' => 'Escopos ausentes: :scopes.',

    'retry_after' => 'Tente novamente em :seconds s.',

    'scopes' => [
        'leitura' => 'Leitura',
        'envio' => 'Envio',
        'grupos' => 'Grupos',
        'midia' => 'Mídia',
    ],

    'consent' => [
        'required' => 'Aceite o termo de consentimento do WhatsApp na versão vigente antes de continuar.',
        'version_mismatch' => 'A versão do termo de consentimento não é a vigente. Recarregue o painel e aceite o termo atual.',
        'invalid_scopes' => 'Escolha de 1 a 4 escopos válidos, sem repetição: leitura, envio, grupos ou midia.',
    ],

    'session' => [
        'upstream_unavailable' => 'Não foi possível falar com o serviço do WhatsApp agora. Tente novamente em instantes.',
        'not_found' => 'Nenhuma sessão do WhatsApp foi criada.',
        'not_pairable' => 'A sessão do WhatsApp não pode ser pareada agora: ela já está conectada ou foi suspensa.',
    ],

    'schedules' => [
        'not_cancellable' => 'Só agendamentos ativos ou pausados podem ser cancelados.',
        'not_resumable' => 'Só agendamentos pausados podem ser retomados.',
    ],

    'approvals' => [
        'consent_revoked' => 'Consentimento do WhatsApp revogado.',
        'session_removed' => 'WhatsApp removido pelo usuário.',
    ],

    'settings' => [
        'cross_field' => 'O valor de :max deve ser maior ou igual ao de :min.',
    ],
];
