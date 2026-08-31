<?php

declare(strict_types=1);

return [
    'heading' => 'Caixas de entrada',
    'intro' => 'Liga caixas de entrada do Gmail e do Microsoft 365 para o Beatrax as poder analisar à procura de recibos.',
    'intro_phone' => 'A análise das caixas de entrada é feita na aplicação de computador, não neste telemóvel.',

    'phone_heading' => 'Este telemóvel não analisa caixas de correio',
    'phone_body' => 'Liga o Gmail ou o Microsoft 365 na aplicação de computador — os recibos que encontrar chegam aqui por sincronização.',
    'connection_canceled' => 'Ligação cancelada.',
    'connection_failed' => 'Não foi possível concluir a ligação.',

    'backfilling' => 'A recuperar histórico',
    'backfill_progress' => ':fetched / ~:count mensagem|:fetched / ~:count mensagens',

    'connect_heading' => 'Liga o teu e-mail',
    'connect_body' => 'Importa recibos do PayPal, da ICS Cards, do Google Play e de outros comerciantes, dando ao Beatrax acesso só de leitura a uma ou mais das tuas caixas de entrada.',
    'connect_body_phone' => 'Os recibos do PayPal, da ICS Cards, do Google Play e de outros comerciantes são importados pela aplicação de computador, a partir das caixas a que lhe dás acesso só de leitura. Este telemóvel mostra o que essa importação encontra.',
    'connect_gmail' => 'Ligar o Gmail',
    'connect_microsoft' => 'Ligar o Microsoft 365',
    'readonly_note' => 'O Beatrax apenas lê mensagens. Nunca envia, etiqueta, move nem elimina nada na tua caixa de entrada.',

    'months' => ':count mês|:count meses',
    'not_scanned_yet' => 'ainda não analisada',
    'not_scanned_yet_phone' => 'não analisada neste telemóvel',
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

    'error_detail' => 'A última análise não foi concluída. Experimente «Analisar agora» ou volte a ligar esta caixa.',
    'oauth_no_code' => 'O teu fornecedor de e-mail devolveu-te sem o código de que o Beatrax precisa para terminar, por isso não foi ligada nenhuma caixa. Recomeça a ligação.',
    'oauth_grant_refused' => 'O teu fornecedor de e-mail recusou a permissão dada ao Beatrax — expirou ou foi retirada. Recomeça a ligação e concede-a.',
    'oauth_exchange_failed' => 'O teu fornecedor de e-mail não concluiu a ligação, por isso não foi adicionada nenhuma caixa. Tenta outra vez daqui a alguns minutos.',
    'oauth_not_saved' => 'Não foi possível guardar a ligação neste dispositivo, por isso não foi adicionada nenhuma caixa. Tenta outra vez — se continuar a falhar, o registo da app guarda o que a impediu.',
    'oauth_no_offline_access_google' => 'A Google não concedeu a permissão duradoura de que o Beatrax precisa, por isso esta caixa deixaria de ser analisada dentro de uma hora. Publica o teu ecrã de consentimento OAuth em produção e liga outra vez.',
    'oauth_no_offline_access' => 'O teu fornecedor de e-mail não concedeu a permissão duradoura de que o Beatrax precisa, por isso esta caixa deixaria de ser analisada dentro de uma hora. Liga outra vez e permite o acesso offline quando for pedido.',
    'oauth_no_offline_access_google_phone' => 'A Google não concedeu a permissão duradoura de que o Beatrax precisa, por isso não foi ligada nenhuma caixa. Publica o teu ecrã de consentimento OAuth em produção e liga outra vez — a análise em si é feita na aplicação de computador.',
    'oauth_no_offline_access_phone' => 'O teu fornecedor de e-mail não concedeu a permissão duradoura de que o Beatrax precisa, por isso não foi ligada nenhuma caixa. Liga outra vez e permite o acesso offline quando for pedido — a análise em si é feita na aplicação de computador.',

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
    'gmail_card_body_phone' => 'O Gmail é analisado pela aplicação de computador. Uma conta ligada aqui nunca é analisada sozinha.',
    'microsoft_card_body_phone' => 'O Microsoft 365 e o Outlook.com são analisados pela aplicação de computador. Uma conta ligada aqui nunca é analisada sozinha.',

    'discovered_heading' => 'Remetentes descobertos',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (extratos)',
    ],
    'discovered_body' => 'Remetentes que parecem enviar recibos mas que ainda não estão na tua lista de recibos conhecidos. Adiciona os que queres que o Beatrax analise; dispensa os restantes.',
    'last_seen' => 'visto pela última vez',
    'seen_times' => 'Visto :count vez|Visto :count vezes',
    'add' => 'Adicionar',
    'add_aria' => 'Adicionar :email',
    'dismiss' => 'Dispensar',
    'dismiss_aria' => 'Dispensar :email',

    'toast' => [
        'reconnect_first' => 'Volte a ligar esta caixa de entrada antes de analisar.',
        'scan_in_progress' => 'Já há uma análise em curso.',
        'scan_started' => 'Análise iniciada.',
        'sender_added' => 'Remetente adicionado.',
        'sender_dismissed' => 'Remetente dispensado.',
    ],
];
