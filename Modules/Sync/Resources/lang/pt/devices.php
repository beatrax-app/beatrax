<?php

declare(strict_types=1);

return [
    'heading' => 'Dispositivos e sincronização',

    'enable_sync' => 'Ativar a sincronização',
    'enable_sync_help' => 'Partilha os teus dados em segurança entre dispositivos de confiança. Requer um bloqueio da aplicação. Uma vez ativo, os teus dados ficam cifrados e o bloqueio da aplicação já não pode ser desligado.',

    'app_lock_notice' => 'Define primeiro um bloqueio da aplicação para ativares a sincronização.',
    'go_to_app_lock' => 'Ir para Bloqueio da aplicação',

    'identity_unreadable' => 'A identidade de sincronização deste dispositivo foi criada com outro bloqueio da app e já não abre. Enquanto for assim, este dispositivo não consegue sincronizar nem emparelhar. Restaurar a cópia de segurança da base de dados com que foi criada torna-a legível de novo.',
    'identity_unreadable_replace_help' => 'Também podes começar de novo: este dispositivo recebe uma identidade nova, a antiga fica guardada sem uso e os dispositivos que emparelhaste antes têm de ser emparelhados outra vez.',
    'identity_unreadable_replace' => 'Criar uma identidade nova para este dispositivo',

    'encrypted_at_rest' => 'Dados encriptados em repouso',
    'encrypted_at_rest_scope' => 'As notas, as descrições das transações e os nomes e IBAN de quem pagas estão encriptados no livro de registos com a palavra-passe do bloqueio da app. Os valores, as datas e o nome e IBAN da tua própria conta não estão. O índice de pesquisa guarda a sua própria cópia legível de a quem pagas, das descrições das tuas transações e das tuas notas fiscais, e alguns nomes de comerciantes continuam legíveis noutras partes do ficheiro da base de dados.',
    'on' => 'Ligado',
    'securing' => 'A proteger os teus dados…',
    'do_not_close' => 'Não feches esta janela.',
    'encryption_progress_aria' => 'Progresso da encriptação',
    'not_encrypted_offer' => 'Os seus dados não estão cifrados em repouso. A cifragem esconde a quem paga se este dispositivo for perdido ou roubado — montantes, datas e o índice de pesquisa continuam legíveis.',
    'enable_encryption' => 'Ativar a encriptação',

    'your_devices' => 'Os teus dispositivos',

    'moved_help' => 'O emparelhamento, os nomes dos dispositivos e a encriptação estão agora junto ao teu estado de sincronização.',
    'moved_cta' => 'Abrir Sincronização e dispositivo',
    'device_name' => 'Nome do dispositivo',
    'save' => 'Guardar',
    'peer_default_name' => 'Dispositivo emparelhado',
    'rename_device' => 'Mudar o nome do dispositivo',
    'this_device' => 'Este dispositivo',
    'removed' => 'Removido',
    'confirmed' => 'Confirmado',
    'awaiting_confirmation' => 'A aguardar confirmação',
    'safety_number_words' => 'Palavras do número de segurança:',
    'paired' => 'Emparelhado',
    'remove_aria' => 'Remover :name',
    'remove' => 'Remover',
    'pair_new_device' => 'Emparelhar um novo dispositivo',

    'pairing_waiting' => 'Conclua o emparelhamento com :name',
    'pairing_waiting_help' => 'Os dois ecrãs têm de mostrar as mesmas seis palavras para o emparelhamento contar. Reabra para as comparar.',
    'pairing_waiting_resume' => 'Continuar o emparelhamento',
    'pairing_waiting_lock_override' => 'Desbloquear reabre este emparelhamento em vez de o deixar expirar, por isso dura mais do que o tempo de bloqueio que definiu. Termina quando o concluir ou cancelar.',

    'relay_endpoint' => 'Endpoint do relay',
    'relay_endpoint_help' => 'Opcional. Quando definido, os dispositivos offline sincronizam através deste relay. Deixa vazio para usares apenas LAN&#8209;direta.',
    'relay_endpoint_aria' => 'URL do endpoint do relay',
    'relay_insecure_warning' => 'Este endpoint de relay usa HTTP simples. Embora o relay nunca desencripte os teus dados, uma ligação insegura expõe os tamanhos encriptados e os tempos a quem observe a rede. Usa um endpoint <strong>https://</strong> para teres a melhor privacidade.',

    'enable_at_rest' => 'Ativar a encriptação em repouso',
    'enable_at_rest_body' => 'Os teus dados vão ser encriptados com a frase-passe do bloqueio da aplicação. Será criada automaticamente uma cópia de segurança antes da migração.',
    'no_recovery_warning' => 'Se perderes a frase-passe do bloqueio da aplicação e não tiveres cópia de segurança nem outro dispositivo de confiança, os teus dados não podem ser recuperados.',
    'recover_help' => 'Para recuperares o acesso, volta a emparelhar este dispositivo a partir de outro dispositivo de confiança ou usa a tua cópia de segurança encriptada independente.',
    'amounts_plaintext' => 'Os montantes não são encriptados em repouso — os saldos e os totais continuam legíveis para que os teus totais mensais continuem a bater certo.',
    'search_plaintext' => 'O índice de pesquisa guarda uma cópia em texto simples do nome do comerciante e da descrição para que a pesquisa de texto integral continue a funcionar.',
    'keep_unencrypted' => 'Manter os dados sem encriptação',
    'encryption_enabled' => 'Encriptação ativada',
    'encryption_enabled_scope' => 'As notas, as descrições e a quem pagas estão agora encriptados com a palavra-passe do bloqueio da app. Os valores, as datas e o índice de pesquisa continuam legíveis.',
    'done_encryption_enabled' => 'Concluído — encriptação ativada',
    'encryption_failed' => 'A configuração da encriptação falhou',
    'encryption_failed_body' => 'Os teus dados não foram alterados. A tua cópia de segurança foi preservada.',
    'close_no_changes' => 'Fechar — não foi alterado nada',

    'remove_this_device' => 'Remover este dispositivo',
    'removing' => 'A remover:',
    'remove_rotates_key' => 'Remover este dispositivo faz a rotação da chave de encriptação, para que ele não receba atualizações futuras.',
    'remove_cannot_erase' => 'Não é possível apagar os dados que já estão nesse dispositivo. Se este dispositivo se perdeu ou foi roubado, considera expostos todos os dados que continha.',
    'remove_device' => 'Remover o dispositivo',
    'keep_device' => 'Manter o dispositivo',
    'rotating_key' => 'A rodar a chave de encriptação…',

    'flash' => [
        'app_lock_first' => 'Define primeiro um bloqueio da aplicação para ativares a sincronização.',
        'enable_failed' => 'Não foi possível ativar a sincronização. Confirma que o bloqueio da aplicação está ativo e tenta de novo.',
        'identity_replaced' => 'Este dispositivo tem uma nova identidade de sincronização. Emparelha os teus outros dispositivos outra vez.',
        'identity_replace_failed' => 'Não foi possível pôr de parte a identidade antiga do dispositivo. Tenta novamente.',
        'cannot_remove_self' => 'Não podes remover este dispositivo — é o que estás a usar.',
        'remove_failed' => 'Não foi possível remover o dispositivo. Tenta de novo.',
        'app_lock_first_settings' => 'Define primeiro um bloqueio da aplicação para alterares as definições de sincronização.',
        'relay_cleared' => 'Endpoint do relay limpo.',
        'relay_saved' => 'Endpoint do relay guardado.',
        'relay_save_failed' => 'Não foi possível guardar o endpoint do relay: :message',
    ],
    'app_lock_permanent' => 'Depois de os dados estarem cifrados, o bloqueio da aplicação já não pode ser desligado — guarda a única chave, e não há regresso a dados não cifrados.',
    'backlog_heading' => 'À espera de ser adicionado',
    'backlog_deferred' => 'Este dispositivo recebeu dados de outro dispositivo e ainda não os adicionou às suas contas. Nada se perde — são aplicados automaticamente, normalmente num instante.',
    'backlog_awaiting_key' => 'Este dispositivo recebeu dados para os quais ainda não tem a chave. Nada se perde. Abra a aplicação no dispositivo emparelhado enquanto este está aberto, para que os dois possam ligar-se e a chave possa ser enviada.',
];
