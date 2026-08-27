<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Upozorenja sustava',

    'actions' => [
        'install_next_launch' => 'Instaliraj pri sljedećem pokretanju',
        'install_next_launch_aria' => 'Instaliraj pri sljedećem pokretanju — označava upozorenje sustava br. :id kao riješeno',
        'skip_version' => 'Preskoči ovu verziju',
        'release_notes' => 'Bilješke uz izdanje →',
        'update_now' => 'Ažuriraj sada',
        'update_now_aria' => 'Ažuriraj sada — označava upozorenje sustava br. :id kao riješeno',
        'remind_later' => 'Podsjeti me kasnije',
        'mark_resolved' => 'Označi kao riješeno',
        'mark_resolved_aria' => 'Označi kao riješeno — upozorenje sustava br. :id',
    ],

    'messages' => [
        'update_available' => 'Dostupno je ažuriranje — Beatrax :version je spreman. Instalirat će se pri sljedećem pokretanju.',
        'update_stale' => 'Koristiš verziju :current — verzija :latest dostupna je već 30 dana. Ažuriraj sada.',
        'update_critical' => 'Dostupno je kritično ažuriranje — verzija :version ispravlja :summary. Instaliraj je što prije.',
        'backup_corrupt_with_path' => 'Sigurnosna kopija zapisana u :timestamp nije prošla provjeru cjelovitosti. Pregledaj :path. Riješi to prije nego što se osloniš na sigurnosne kopije.',
        'backup_corrupt_no_path' => 'Sigurnosna kopija pokrenuta u :timestamp prekinuta je prije nego što je nastala ijedna datoteka — izvorna baza nije prošla provjeru cjelovitosti. Riješi to prije nego što se osloniš na sigurnosne kopije.',

        'backup_overdue' => 'Najnovija provjerena sigurnosna kopija stara je :hoursh. Pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> ili pričekaj zakazano pokretanje u 03:00.',
        'wal_mode_missing' => 'SQLite nije u WAL načinu (trenutačno :mode). Istodobni zapisi mogli bi zastati. Za upute pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'synchronous_misconfigured' => 'SQLite razina synchronous je :level (očekuje se NORMAL/1). Ponašanje trajnosti može se razlikovati od konfiguracije. Za upute pokreni <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'oauth_scrub_set_failed' => 'Skrivanje OAuth tajni ne radi. Zapisi i izvadci revizije mogu sadržavati neskrivene tokene do sljedećeg uspješnog učitavanja.',
        'oauth_reauth_required' => 'OAuth tajne premještene su u pohranu po korisniku. Ponovno autorizirajte Gmail i Microsoft kako bi se nastavilo skeniranje e-pošte. Stara datoteka s tajnama preimenovana je u :file radi vraćanja.',
        'oauth_reconsent' => 'Ponovno povežite svoj :provider',
        'reconnect_link' => 'Poveži ponovno →',
    ],
];
