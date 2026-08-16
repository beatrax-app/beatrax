<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systémové upozornenia',

    'actions' => [
        'install_next_launch' => 'Nainštalovať pri ďalšom spustení',
        'install_next_launch_aria' => 'Nainštalovať pri ďalšom spustení — označí systémové upozornenie #:id ako vyriešené',
        'skip_version' => 'Preskočiť túto verziu',
        'release_notes' => 'Poznámky k vydaniu →',
        'update_now' => 'Aktualizovať teraz',
        'update_now_aria' => 'Aktualizovať teraz — označí systémové upozornenie #:id ako vyriešené',
        'remind_later' => 'Pripomenúť neskôr',
        'mark_resolved' => 'Označiť ako vyriešené',
        'mark_resolved_aria' => 'Označiť ako vyriešené — systémové upozornenie #:id',
    ],

    'messages' => [
        'update_available' => 'Je dostupná aktualizácia — Beatrax :version je pripravený. Nainštaluje sa pri ďalšom spustení.',
        'update_stale' => 'Používaš verziu :current — verzia :latest je dostupná už 30 dní. Aktualizuj teraz.',
        'update_critical' => 'Je dostupná kritická aktualizácia — verzia :version opravuje: :summary. Nainštaluj ju čo najskôr.',
        'backup_corrupt_with_path' => 'Záloha zapísaná o :timestamp neprešla kontrolou integrity. Skontroluj :path. Vyrieš to skôr, než sa na zálohy spoľahneš.',
        'backup_corrupt_no_path' => 'Záloha spustená o :timestamp sa prerušila skôr, než vznikol akýkoľvek súbor — zdrojová databáza neprešla kontrolou integrity. Vyrieš to skôr, než sa na zálohy spoľahneš.',

        'backup_overdue' => 'Najnovšia overená záloha je :hoursh stará. Spusti <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> alebo počkaj na plánované spustenie o 03:00.',
        'wal_mode_missing' => 'SQLite nie je v režime WAL (aktuálne :mode). Súbežné zápisy sa môžu zaseknúť. Pokyny získaš spustením <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'synchronous_misconfigured' => 'Úroveň synchronous v SQLite je :level (očakáva sa NORMAL/1). Sémantika trvanlivosti sa môže líšiť od konfigurácie. Pokyny získaš spustením <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'reconnect_link' => 'Znova pripojiť →',
    ],
];
