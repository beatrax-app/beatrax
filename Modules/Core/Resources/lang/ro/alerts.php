<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Alerte de sistem',

    'actions' => [
        'install_next_launch' => 'Instalează la următoarea pornire',
        'install_next_launch_aria' => 'Instalează la următoarea pornire — marchează alerta de sistem #:id ca rezolvată',
        'skip_version' => 'Omite această versiune',
        'release_notes' => 'Note de versiune →',
        'update_now' => 'Actualizează acum',
        'update_now_aria' => 'Actualizează acum — marchează alerta de sistem #:id ca rezolvată',
        'remind_later' => 'Amintește-mi mai târziu',
        'mark_resolved' => 'Marchează ca rezolvată',
        'mark_resolved_aria' => 'Marchează ca rezolvată — alerta de sistem #:id',
    ],

    'messages' => [
        'update_available' => 'Actualizare disponibilă — Beatrax :version este gata. Se va instala la următoarea pornire.',
        'update_stale' => 'Folosești versiunea :current — versiunea :latest este disponibilă de 30 de zile. Actualizează acum.',
        'update_critical' => 'Actualizare critică disponibilă — versiunea :version rezolvă :summary. Instaleaz-o cât mai curând.',
        'backup_corrupt_with_path' => 'Copia de rezervă scrisă la :timestamp nu a trecut verificarea de integritate. Verifică :path. Rezolvă problema înainte să te bazezi pe copiile de rezervă.',
        'backup_corrupt_no_path' => 'Copia de rezervă încercată la :timestamp s-a oprit înainte să fie produs vreun fișier — baza de date sursă nu a trecut verificarea de integritate. Rezolvă problema înainte să te bazezi pe copiile de rezervă.',

        'backup_overdue' => 'Cea mai recentă copie de rezervă verificată are o vechime de :hoursh. Rulează <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> sau așteaptă rularea programată de la 03:00.',
        'wal_mode_missing' => 'SQLite nu rulează în modul WAL (momentan :mode). Scrierile simultane se pot bloca. Rulează <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> pentru îndrumare.',
        'synchronous_misconfigured' => 'Nivelul synchronous al SQLite este :level (așteptat NORMAL/1). Semantica durabilității poate diferi de configurație. Rulează <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> pentru îndrumare.',
        'reconnect_link' => 'Reconectează →',
    ],
];
