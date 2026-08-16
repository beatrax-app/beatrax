<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systemvarningar',

    'actions' => [
        'install_next_launch' => 'Installera vid nästa start',
        'install_next_launch_aria' => 'Installera vid nästa start — markerar systemvarning #:id som åtgärdad',
        'skip_version' => 'Hoppa över den här versionen',
        'release_notes' => 'Versionsinformation →',
        'update_now' => 'Uppdatera nu',
        'update_now_aria' => 'Uppdatera nu — markerar systemvarning #:id som åtgärdad',
        'remind_later' => 'Påminn mig senare',
        'mark_resolved' => 'Markera som åtgärdad',
        'mark_resolved_aria' => 'Markera som åtgärdad — systemvarning #:id',
    ],

    'messages' => [
        'update_available' => 'Uppdatering tillgänglig — Beatrax :version är klar. Den installeras vid nästa start.',
        'update_stale' => 'Du kör version :current — version :latest har funnits i 30 dagar. Uppdatera nu.',
        'update_critical' => 'Kritisk uppdatering tillgänglig — version :version åtgärdar :summary. Installera så snart som möjligt.',
        'backup_corrupt_with_path' => 'Säkerhetskopian som skrevs :timestamp klarade inte integritetskontrollen. Granska :path. Åtgärda detta innan du förlitar dig på säkerhetskopior.',
        'backup_corrupt_no_path' => 'Säkerhetskopieringen som försöktes :timestamp avbröts innan någon fil skapades — källdatabasen klarade inte integritetskontrollen. Åtgärda detta innan du förlitar dig på säkerhetskopior.',

        'backup_overdue' => 'Den senaste verifierade säkerhetskopian är :hoursh gammal. Kör <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> eller vänta på den schemalagda körningen kl. 03:00.',
        'wal_mode_missing' => 'SQLite är inte i WAL-läge (för närvarande :mode). Samtidiga skrivningar kan fastna. Kör <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> för vägledning.',
        'synchronous_misconfigured' => 'SQLites synchronous-nivå är :level (förväntat NORMAL/1). Hållbarheten kan skilja sig från konfigurationen. Kör <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> för vägledning.',
        'reconnect_link' => 'Återanslut →',
    ],
];
