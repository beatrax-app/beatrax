<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Alertas do sistema',

    'actions' => [
        'download_and_install' => 'Transferir e instalar',
        'download_and_install_aria' => 'Transferir e instalar — marca o alerta do sistema n.º :id como resolvido',
        'skip_version' => 'Ignorar esta versão',
        'release_notes' => 'Notas da versão →',
        'update_now' => 'Atualizar agora',
        'update_now_aria' => 'Atualizar agora — marca o alerta do sistema n.º :id como resolvido',
        'remind_later' => 'Lembrar-me mais tarde',
        'mark_resolved' => 'Marcar como resolvido',
        'mark_resolved_aria' => 'Marcar como resolvido — alerta do sistema n.º :id',
        'assign_in_budgets' => 'Atribuir em Orçamentos',
        'dismiss' => 'Dispensar',
        'dismiss_aria' => 'Dispensar — alerta do sistema n.º :id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'os alertas de orçamento',
        'daily-triggers' => 'os lembretes diários e o resumo',
    ],

    'messages' => [
        'update_available' => 'Atualização disponível — Beatrax :version. Nada é transferido até escolheres instalar; o Beatrax fecha-se depois e reabre na versão nova.',
        'update_refused' => 'O Beatrax descarregou a versão :version e recusou instalá-la — o ficheiro não correspondia à assinatura do editor, por isso nada foi alterado neste dispositivo. Um descarregamento danificado pode causar isto. Se continuar a acontecer, não instales o Beatrax a partir dessa origem.',
        'update_stale' => 'Estás na versão :current — a versão :latest já está disponível há 30 dias. Atualiza agora.',
        'update_critical' => 'Atualização crítica disponível — a versão :version corrige :summary. Instala assim que possível.',
        'backup_corrupt_with_path' => 'A cópia de segurança escrita a :timestamp não passou na verificação de integridade. Inspeciona :path. Resolve isto antes de confiares nas cópias de segurança.',
        'backup_corrupt_no_path' => 'A cópia de segurança tentada a :timestamp foi interrompida antes de produzir qualquer ficheiro — a base de dados de origem não passou na verificação de integridade. Resolve isto antes de confiares nas cópias de segurança.',
        'backup_write_failed' => 'A cópia de segurança iniciada às :timestamp não foi concluída: a base de dados passou nas verificações, os ficheiros da cópia não puderam ser escritos. Verifica o espaço livre e as permissões da pasta de cópias.',
        'backup_restore_failed' => 'O restauro iniciado às :timestamp não foi concluído. Os teus dados anteriores foram guardados antes em :snapshot.',

        'backup_overdue' => 'A cópia de segurança verificada mais recente tem :hoursh. O Beatrax faz esta cópia sozinho, uma vez por dia, enquanto a app está aberta — não há nada para executares à mão. Se continuar com esta idade, a app não esteve aberta quando calhou a execução diária.',
        'backup_none_found' => 'Não foi encontrada nenhuma cópia de segurança verificada na pasta de cópias. O Beatrax faz esta cópia sozinho, uma vez por dia, enquanto a app está aberta — não há nada para executares à mão.',
        'wal_mode_missing' => 'A base de dados não está em modo WAL (atualmente :mode), por isso guardar pode parar enquanto decorre uma tarefa em segundo plano. O Beatrax define WAL sempre que arranca, por isso reiniciar costuma resolver.',
        'synchronous_misconfigured' => 'O nível de durabilidade da base de dados é :level em vez do NORMAL esperado. O Beatrax define-o sempre que arranca, por isso reiniciar costuma resolver.',
        'oauth_scrub_set_failed' => 'A ocultação de segredos OAuth está fora de serviço. Os registos e os extratos de auditoria podem conter tokens não ocultados até ao próximo carregamento bem-sucedido.',
        'oauth_reauth_required' => 'Os segredos OAuth foram movidos para armazenamento por utilizador. Volte a autorizar o Gmail e a Microsoft para retomar a análise de e-mail. O ficheiro de segredos anterior foi renomeado para :file para permitir a reversão.',
        'oauth_reconsent' => 'Volte a ligar o seu :provider',
        'auth_recovery_code_consumed' => 'Código de recuperação usado por :username.',
        'auth_recovery_code_failed' => 'Tentativa de código de recuperação falhada para :username.',
        'auth_lock_hard_cap_reached' => 'Sessão terminada após demasiadas tentativas de PIN falhadas.',
        'open_banking_reconsent' => 'Volte a ligar o seu banco',
        'open_banking_nothing_imported' => 'O seu banco enviou transações, mas o Beatrax não conseguiu registar nenhuma, pelo que nada chegou ao seu registo. Abra as definições de Open banking para ver porquê.',
        'auth_lock_corrupted_key' => 'O seu PIN não consegue abrir o bloqueio da aplicação neste dispositivo: a chave guardada está ilegível. Inicie sessão com a palavra-passe da conta para definir um novo PIN.',
        'sync_gdk_rewrap_failed' => 'O reempacotamento do porta-chaves GDK falhou após uma alteração da frase-passe do bloqueio da aplicação — os dados cifrados podem ser irrecuperáveis até o porta-chaves ser reempacotado.',
        'worker_crashed' => 'O processamento em segundo plano do Beatrax parou inesperadamente. As importações e as análises de e-mail estão em pausa. Reabra a aplicação para o reiniciar.',
        'auth_lock_key_material_stranded' => 'A cifragem em repouso está ativa nesta conta, mas nenhum invólucro do bloqueio da aplicação retém já a chave de dados, pelo que cada nota, descrição e detalhe de contraparte cifrado é lido como vazio. Restaure uma cópia de segurança cifrada feita enquanto a chave ainda funcionava, ou volte a configurar esta conta num dispositivo que ainda a tenha.',
        'auth_lock_recovery_wrap_stale' => 'A palavra-passe da conta foi alterada sem que o invólucro de recuperação do bloqueio da aplicação fosse reempacotado, pelo que essa palavra-passe já não abre o bloqueio. O PIN ainda abre. Volte a associar a palavra-passe da conta nas definições de bloqueio enquanto o PIN ainda for conhecido — caso contrário, um PIN esquecido não deixa nada atrás de si.',
        'reconnect_link' => 'Voltar a ligar →',
        'pots_category_link_retired' => 'O orçamento por envelopes substituiu as reservas ligadas a uma categoria. :amount de :count reserva arquivada volta a estar não alocado e espera que o atribua.|O orçamento por envelopes substituiu as reservas ligadas a uma categoria. :amount de :count reservas arquivadas volta a estar não alocado e espera que o atribua.',
        'notifications_deferred_pass_failed' => 'O Beatrax não conseguiu calcular :pass neste dispositivo, por isso pode faltar algum. Tenta novamente sempre que abre a aplicação.',
    ],
];
