<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Sistemska upozorenja',

    'actions' => [
        'install_next_launch' => 'Instaliraj pri sledećem pokretanju',
        'install_next_launch_aria' => 'Instaliraj pri sledećem pokretanju — označava sistemsko upozorenje br. :id kao rešeno',
        'skip_version' => 'Preskoči ovu verziju',
        'release_notes' => 'Beleške uz izdanje →',
        'update_now' => 'Ažuriraj sada',
        'update_now_aria' => 'Ažuriraj sada — označava sistemsko upozorenje br. :id kao rešeno',
        'remind_later' => 'Podseti me kasnije',
        'mark_resolved' => 'Označi kao rešeno',
        'mark_resolved_aria' => 'Označi kao rešeno — sistemsko upozorenje br. :id',
    ],

    'messages' => [
        'update_available' => 'Dostupno je ažuriranje — Beatrax :version je spreman. Instaliraće se pri sledećem pokretanju.',
        'update_stale' => 'Koristiš verziju :current — verzija :latest je dostupna već 30 dana. Ažuriraj sada.',
        'update_critical' => 'Dostupno je kritično ažuriranje — verzija :version ispravlja :summary. Instaliraj je što pre.',
        'backup_corrupt_with_path' => 'Rezervna kopija zapisana u :timestamp nije prošla proveru integriteta. Pregledaj :path. Reši to pre nego što se osloniš na rezervne kopije.',
        'backup_corrupt_no_path' => 'Rezervna kopija pokrenuta u :timestamp prekinuta je pre nego što je nastala ijedna datoteka — izvorna baza nije prošla proveru integriteta. Reši to pre nego što se osloniš na rezervne kopije.',

        'backup_overdue' => 'Najnovija proverena rezervna kopija stara je :hoursh. Pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> ili sačekaj zakazano pokretanje u 03:00.',
        'wal_mode_missing' => 'SQLite nije u WAL režimu (trenutno :mode). Istovremeni upisi mogu da zastanu. Za uputstva pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'synchronous_misconfigured' => 'SQLite nivo synchronous je :level (očekuje se NORMAL/1). Ponašanje trajnosti može da se razlikuje od konfiguracije. Za uputstva pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'reconnect_link' => 'Poveži ponovo →',
    ],
];
