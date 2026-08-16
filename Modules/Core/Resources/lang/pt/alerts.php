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
        'reconnect_link' => 'Voltar a ligar →',
    ],
];
