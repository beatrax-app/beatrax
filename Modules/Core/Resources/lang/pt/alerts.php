<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Alertas do sistema',

    'actions' => [
        'install_next_launch' => 'Instalar no próximo arranque',
        'install_next_launch_aria' => 'Instalar no próximo arranque — marca o alerta do sistema n.º :id como resolvido',
        'skip_version' => 'Ignorar esta versão',
        'release_notes' => 'Notas da versão →',
        'update_now' => 'Atualizar agora',
        'update_now_aria' => 'Atualizar agora — marca o alerta do sistema n.º :id como resolvido',
        'remind_later' => 'Lembrar-me mais tarde',
        'mark_resolved' => 'Marcar como resolvido',
        'mark_resolved_aria' => 'Marcar como resolvido — alerta do sistema n.º :id',
    ],

    'messages' => [
        'update_available' => 'Atualização disponível — o Beatrax :version está pronto. Vai ser instalado no próximo arranque.',
        'update_stale' => 'Estás na versão :current — a versão :latest já está disponível há 30 dias. Atualiza agora.',
        'update_critical' => 'Atualização crítica disponível — a versão :version corrige :summary. Instala assim que possível.',
        'backup_corrupt_with_path' => 'A cópia de segurança escrita a :timestamp não passou na verificação de integridade. Inspeciona :path. Resolve isto antes de confiares nas cópias de segurança.',
        'backup_corrupt_no_path' => 'A cópia de segurança tentada a :timestamp foi interrompida antes de produzir qualquer ficheiro — a base de dados de origem não passou na verificação de integridade. Resolve isto antes de confiares nas cópias de segurança.',

        'backup_overdue' => 'A cópia de segurança verificada mais recente tem :hoursh. Executa <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> ou espera pela execução agendada das 03:00.',
        'wal_mode_missing' => 'O SQLite não está em modo WAL (atualmente :mode). As escritas simultâneas podem bloquear. Executa <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> para obteres ajuda.',
        'synchronous_misconfigured' => 'O nível synchronous do SQLite é :level (esperava-se NORMAL/1). A durabilidade pode comportar-se de forma diferente da configurada. Executa <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> para obteres ajuda.',
        'oauth_scrub_set_failed' => 'A ocultação de segredos OAuth está fora de serviço. Os registos e os extratos de auditoria podem conter tokens não ocultados até ao próximo carregamento bem-sucedido.',
        'oauth_reauth_required' => 'Os segredos OAuth foram movidos para armazenamento por utilizador. Volte a autorizar o Gmail e a Microsoft para retomar a análise de e-mail. O ficheiro de segredos anterior foi renomeado para :file para permitir a reversão.',
        'oauth_reconsent' => 'Volte a ligar o seu :provider',
        'auth_recovery_code_consumed' => 'Código de recuperação usado por :username.',
        'auth_recovery_code_failed' => 'Tentativa de código de recuperação falhada para :username.',
        'auth_lock_hard_cap_reached' => 'Sessão terminada após demasiadas tentativas de PIN falhadas.',
        'open_banking_reconsent' => 'Volte a ligar o seu banco',
        'auth_lock_corrupted_key' => 'O seu PIN não consegue abrir o bloqueio da aplicação neste dispositivo: a chave guardada está ilegível. Inicie sessão com a palavra-passe da conta para definir um novo PIN.',
        'sync_gdk_rewrap_failed' => 'O reempacotamento do porta-chaves GDK falhou após uma alteração da frase-passe do bloqueio da aplicação — os dados cifrados podem ser irrecuperáveis até o porta-chaves ser reempacotado.',
        'worker_crashed' => 'O processamento em segundo plano do Beatrax parou inesperadamente. As importações e as análises de e-mail estão em pausa. Reabra a aplicação para o reiniciar.',
        'auth_lock_key_material_stranded' => 'A cifragem em repouso está ativa nesta conta, mas nenhum invólucro do bloqueio da aplicação retém já a chave de dados, pelo que cada nota, descrição e detalhe de contraparte cifrado é lido como vazio. Emparelhar com um dispositivo que ainda tenha a chave é o único caminho de volta.',
        'auth_lock_recovery_wrap_stale' => 'A palavra-passe da conta foi alterada sem que o invólucro de recuperação do bloqueio da aplicação fosse reempacotado, pelo que essa palavra-passe já não abre o bloqueio. O PIN ainda abre. Volte a associar a palavra-passe da conta nas definições de bloqueio enquanto o PIN ainda for conhecido — caso contrário, um PIN esquecido não deixa nada atrás de si.',
        'reconnect_link' => 'Voltar a ligar →',
    ],
];
