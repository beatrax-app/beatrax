<?php

declare(strict_types=1);

return [
    'heading' => 'Caixas de entrada',
    'intro' => 'Liga caixas de entrada do Gmail e do Microsoft 365 para o Beatrax as poder analisar à procura de recibos.',

    'connection_canceled' => 'Ligação cancelada.',
    'connection_failed' => 'Não foi possível concluir a ligação.',

    'backfilling' => 'A recuperar histórico',
    'messages_suffix' => 'mensagens',

    'connect_heading' => 'Liga o teu e-mail',
    'connect_body' => 'Importa recibos do PayPal, da ICS Cards, do Google Play e de outros comerciantes, dando ao Beatrax acesso só de leitura a uma ou mais das tuas caixas de entrada.',
    'connect_gmail' => 'Ligar o Gmail',
    'connect_microsoft' => 'Ligar o Microsoft 365',
    'readonly_note' => 'O Beatrax apenas lê mensagens. Nunca envia, etiqueta, move nem elimina nada na tua caixa de entrada.',

    'months' => ':count mês|:count meses',
    'not_scanned_yet' => 'ainda não analisada',
    'last_scanned' => 'última análise',
    'window_prefix' => 'Janela:',
    'edit' => 'Editar',

    'badge' => [
        'idle' => 'Inativa',
        'backfilling' => 'A recuperar histórico',
        'scanning' => 'A analisar',
        'rate_limited' => 'Limite atingido',
        'needs_reauth' => 'Precisa de reautenticação',
        'error' => 'Erro',
    ],

    'retry_seconds' => 'nova tentativa dentro de :ns',
    'retry_minutes' => 'nova tentativa dentro de :nmin',
    'retry_hours' => 'nova tentativa dentro de :nh',

    'reconnect' => 'Voltar a ligar',
    'disconnect' => 'Desligar',
    'scan_now' => 'Analisar agora',
    'scan_in_progress_title' => 'Já há uma análise em curso',

    'add_another' => 'Adicionar outra caixa de entrada',
    'gmail_card_body' => 'Liga uma conta do Gmail para o Beatrax a poder analisar à procura de recibos.',
    'microsoft_card_body' => 'Liga uma conta do Microsoft 365 ou do Outlook.com para o Beatrax a poder analisar à procura de recibos.',

    'discovered_heading' => 'Remetentes descobertos',
    'discovered_body' => 'Remetentes que parecem enviar recibos mas que ainda não estão na tua lista de recibos conhecidos. Adiciona os que queres que o Beatrax analise; dispensa os restantes.',
    'last_seen' => 'visto pela última vez',
    'seen_times' => 'Visto :count vez|Visto :count vezes',
    'add' => 'Adicionar',
    'add_aria' => 'Adicionar :email',
    'dismiss' => 'Dispensar',
    'dismiss_aria' => 'Dispensar :email',

    'toast' => [
        'scan_in_progress' => 'Já há uma análise em curso.',
        'scan_started' => 'Análise iniciada.',
        'sender_added' => 'Remetente adicionado.',
        'sender_dismissed' => 'Remetente dispensado.',
    ],
];
