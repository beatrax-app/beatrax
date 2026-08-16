<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Avvisi di sistema',

    'actions' => [
        'install_next_launch' => 'Installa al prossimo avvio',
        'install_next_launch_aria' => "Installa al prossimo avvio — segna l'avviso di sistema #:id come risolto",
        'skip_version' => 'Salta questa versione',
        'release_notes' => 'Note di rilascio →',
        'update_now' => 'Aggiorna ora',
        'update_now_aria' => "Aggiorna ora — segna l'avviso di sistema #:id come risolto",
        'remind_later' => 'Ricordamelo più tardi',
        'mark_resolved' => 'Segna come risolto',
        'mark_resolved_aria' => 'Segna come risolto — avviso di sistema #:id',
    ],

    'messages' => [
        'update_available' => 'Aggiornamento disponibile — Beatrax :version è pronto. Verrà installato al prossimo avvio.',
        'update_stale' => 'Stai usando la versione :current — la versione :latest è disponibile da 30 giorni. Aggiorna ora.',
        'update_critical' => 'Aggiornamento critico disponibile — la versione :version corregge :summary. Installalo il prima possibile.',
        'backup_corrupt_with_path' => 'Il backup scritto il :timestamp non ha superato il controllo di integrità. Controlla :path. Risolvi prima di fare affidamento sui backup.',
        'backup_corrupt_no_path' => 'Il backup tentato il :timestamp si è interrotto prima di produrre un file — il database di origine non ha superato il controllo di integrità. Risolvi prima di fare affidamento sui backup.',

        'backup_overdue' => 'Il backup verificato più recente risale a :hoursh fa. Esegui <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> oppure attendi il backup pianificato delle 03:00.',
        'wal_mode_missing' => 'SQLite non è in modalità WAL (attualmente :mode). Le scritture simultanee potrebbero bloccarsi. Esegui <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> per assistenza.',
        'synchronous_misconfigured' => 'Il livello synchronous di SQLite è :level (previsto NORMAL/1). La semantica di durabilità potrebbe differire dalla configurazione. Esegui <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> per assistenza.',
        'reconnect_link' => 'Ricollega →',
    ],
];
