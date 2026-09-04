<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Definições',
        'heading' => 'Open banking',
        'subtitle' => 'Vai buscar automaticamente as transações do ASN ou do SNS através do Enable Banking, um agregador PSD2 externo. Desligado por predefinição.',
        'toggle_label' => 'Ativar o open banking',
        'toggle_connected' => 'Ligado a :bank através do Enable Banking.',
        'toggle_off_help' => 'Desligado por predefinição. Requer uma confirmação única e uma configuração guiada.',
        'credentials_unreadable' => 'Não é possível ler as credenciais de open banking guardadas neste dispositivo, por isso o Beatrax não consegue ligar-se ao teu banco.',
        'credentials_unreadable_next' => 'Faz de novo a configuração guiada para as substituir. As transações já importadas não são afetadas.',
        'reconfirm_body' => 'A tua confirmação expirou antes de conseguirmos concluir a ligação. Confirma de novo para acabares de ativar o open banking.',
        'reconfirm_button' => 'Confirmar de novo para concluir a ativação',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Gerir o open banking',
        'not_connected' => 'Nenhum banco ligado. Liga um para importar transações automaticamente.',
        'expired' => 'Consentimento expirado — é preciso voltar a ligar.',
        'revoked' => 'O teu banco terminou a ligação — volta a ligar.',
        'connected' => 'Ligado a :bank através do Enable Banking. Última sincronização :when.',
        'never' => 'nunca',
    ],

    'transparency' => [
        'aggregator_label' => 'Agregador',
        'bank_label' => 'Banco',
        'consent_status_label' => 'Estado do consentimento',
        'pill_expired' => 'Expirado — volta a ligar',
        'pill_expiring' => 'Expira em breve',
        'pill_connected' => 'Ligado',
        'pill_revoked' => 'Terminada pelo teu banco — voltar a ligar',
        'whats_fetched_label' => 'O que é obtido',
        'whats_fetched' => 'Transações contabilizadas + saldos, últimos 90 dias',
        'last_successful_sync_label' => 'Última sincronização bem-sucedida',
        'never' => 'Nunca',
        'last_attempt_label' => 'Última tentativa',
        'last_attempt_failed' => ':when — falhou (:reason)',
        'reason_consent_expired' => 'consentimento expirado',
        'reason_error' => 'erro',
        'reason_truncated' => 'parada mais cedo',
        'reason_nothing_imported' => 'não foi possível registar nada',
        'reason_consent_revoked' => 'terminada pelo teu banco',
        'disconnect_button' => 'Desligar',
    ],

    'consent_banner' => [
        'heading' => 'Consentimento expirado — volta a ligar',
        'heading_revoked' => 'O teu banco terminou a ligação',
        'body' => 'A tua última sincronização bem-sucedida foi :when. Volta a ligar para retomares a sincronização automática.',
        'body_revoked' => 'O teu banco ou o Enable Banking retirou o acesso, por isso a sincronização parou. A tua última sincronização bem-sucedida foi :when. Volta a ligar para retomares.',
        'never' => 'nunca',
        'reconnect' => 'Voltar a ligar',
    ],

    'sync' => [
        'review_import' => 'Rever a importação',
        'reconnect_first' => 'Volta a ligar primeiro',
        'auto_caption' => 'Sincroniza automaticamente uma vez por dia.',
        'sync_now' => 'Sincronizar agora',

        'consent_expired' => 'Consentimento expirado — volta a ligar.',
        'unavailable' => 'O Enable Banking está temporariamente indisponível. Tenta de novo daqui a pouco.',
        'new_found' => ':count nova transação encontrada.|:count novas transações encontradas.',
        'none' => 'Não há transações novas.',
        'none_importable' => 'O teu banco enviou transações, mas não foi possível registar nenhuma. Abre a revisão da importação para veres porquê.',
        'in_progress' => 'Já há uma sincronização em curso. Tente novamente daqui a pouco.',
        'truncated' => 'O teu banco tinha mais transações do que uma sincronização consegue obter, por isso esta execução parou mais cedo. Nada foi registado como sincronizado — a próxima sincronização começa no mesmo ponto.',
    ],

    'disconnect' => [
        'heading' => 'Desligar o open banking?',
        'body' => 'Isto remove as credenciais e o consentimento do Enable Banking que estão guardados. A sincronização automática para de imediato. As transações já importadas para o Beatrax não são afetadas.',
        'confirm' => 'Desligar',
        'cancel' => 'Manter ligado',
    ],

    'ics' => [
        'section_label' => 'Importação de ficheiro — sem credenciais guardadas',
        'heading' => 'Extrato do cartão de crédito ICS',
        'step_login' => 'Inicia sessão',
        'step_download' => 'Transfere o extrato',
        'pdf_statement' => 'Extrato em PDF',
        'step_drop' => 'Larga-o aqui em baixo',
        'drop_zone_label' => 'Larga aqui o ficheiro do teu extrato',
        'drop_zone_hint' => 'ou procura um ficheiro',
        'browse_aria' => 'Procurar um ficheiro de extrato ICS',
        'import_button' => 'Importar o extrato',
        'validation' => [
            'required' => 'Larga aqui o extrato ICS que transferiste do Mijn ICS.',
            'max' => 'Esse ficheiro é demasiado grande. Os extratos ICS em PDF têm normalmente menos de 1 MB cada.',
            'extensions' => 'Isso não é um PDF. O Mijn ICS só exporta extratos em PDF.',
        ],
        'could_not_read' => 'Não foi possível ler :filename. O erro completo está em /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Antes de ligares um serviço externo',
        'body' => 'Ativar o open banking envia o teu consentimento de acesso ao banco e, depois, os teus dados de transações e saldos, diretamente deste dispositivo para o Enable Banking e para o teu banco. O Beatrax não opera nenhum servidor que veja estes dados — mas o Enable Banking e o teu banco veem. Isto é diferente de todos os outros métodos de importação do Beatrax, que nunca enviam dados para lado nenhum.',
        'acknowledge' => 'Compreendo que os meus dados de transações serão partilhados com o Enable Banking e com o meu banco.',
        'confirm' => 'Ativar o open banking',
        'cancel' => 'Cancelar',
    ],

    'wizard' => [
        'heading' => 'Liga o teu banco',
        'intro' => 'O Beatrax usa a tua própria aplicação Enable Banking, para que as tuas credenciais nunca passem por um servidor partilhado. Esta configuração é feita uma só vez por banco.',

        'step1_title' => 'Gera o teu par de chaves local',
        'step1_body' => 'O Beatrax gera um par de chaves RSA neste dispositivo. A chave privada nunca sai dele.',
        'generate_keypair' => 'Gerar par de chaves',
        'public_key_label' => 'Chave pública',
        'copy_public_key' => 'Copiar a chave pública',
        'copied' => 'Copiado',
        'redirect_uri_label' => 'URI de redirecionamento',
        'copy_redirect_uri' => 'Copiar o URI de redirecionamento',

        'step2_title' => 'Regista a aplicação no Enable Banking',
        'step2_body' => 'Abre o portal para programadores do Enable Banking, cria uma aplicação e cola lá a chave pública e o URI de redirecionamento do passo 1.',
        'open_portal' => 'Abrir o portal do Enable Banking ↗',

        'step3_title' => 'Cola o ID da tua aplicação',
        'application_id_label' => 'ID da aplicação',
        'step3_help' => 'Isto fica guardado num ficheiro local fora da base de dados, com permissões restritivas, e nunca sai deste dispositivo.',

        'step4_title' => 'Escolhe o teu banco',
        'via_enable_banking' => 'através do Enable Banking',
        'other_institution' => 'Outra instituição',
        'institution_id_placeholder' => 'ID da instituição',

        'step5_title' => 'Conclui o consentimento no teu navegador',
        'step5_body' => 'Clica aqui em baixo para abrires o ecrã de início de sessão e consentimento do teu banco. Conclui o início de sessão e qualquer passo de autenticação de 2 fatores; depois voltas para aqui automaticamente para acabares de ativar o Open Banking.',
        // i18n-review: pt · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Toca aqui em baixo para abrires o ecrã de início de sessão e consentimento do teu banco. Conclui o início de sessão e qualquer passo de autenticação de 2 fatores; depois voltas para aqui automaticamente para acabares de ativar o Open Banking.',

        'cancel' => 'Cancelar',
        'continue' => 'Continuar →',
        'continue_to_bank' => 'Continuar para :bank →',
        'your_bank' => 'o teu banco',

        'errors' => [
            'save_keypair_failed' => 'Não foi possível guardar o teu par de chaves no disco — verifica as permissões da tua pasta de segredos e tenta de novo.',
            'generate_failed' => 'Não foi possível gerar um par de chaves neste dispositivo — verifica a tua configuração do OpenSSL.',
            'export_failed' => 'Não foi possível exportar o par de chaves gerado.',
            'read_public_failed' => 'Não foi possível ler a chave pública gerada.',
            'generate_first' => 'Gera um par de chaves antes de continuares.',
            'paste_application_id' => 'Cola o ID da aplicação do portal do Enable Banking antes de continuares.',
            'save_application_id_failed' => 'Não foi possível guardar o ID da tua aplicação no disco — verifica as permissões da tua pasta de segredos e tenta de novo.',
            'choose_bank' => 'Escolhe um banco antes de continuares.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Conclui primeiro o assistente de configuração do Open Banking.',
        'no_bank_chosen' => 'Escolhe um banco antes de ligares.',
        'no_consent_url' => 'O Enable Banking não devolveu nenhum URL de consentimento.',
        'unparseable_consent_url' => 'O Enable Banking devolveu um URL de consentimento impossível de interpretar.',
        'non_public_consent_host' => 'O Enable Banking devolveu um anfitrião de consentimento não público.',
        'unsafe_consent_url' => 'O Enable Banking devolveu um URL de consentimento inseguro.',
        'no_authorization_code' => 'O callback do Enable Banking não devolveu nenhum código de autorização.',
        'no_session_id' => 'O Enable Banking não devolveu nenhum ID de sessão.',
        'oauth_state_mismatch' => 'Esse link de ligação expirou ou já foi utilizado. Comece novamente a ligação ao seu banco.',
    ],
];
