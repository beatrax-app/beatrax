<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Sistemska opozorila',

    'actions' => [
        'install_next_launch' => 'Namesti ob naslednjem zagonu',
        'install_next_launch_aria' => 'Namesti ob naslednjem zagonu — sistemsko opozorilo št. :id označi kot rešeno',
        'skip_version' => 'Preskoči to različico',
        'release_notes' => 'Opombe ob izdaji →',
        'update_now' => 'Posodobi zdaj',
        'update_now_aria' => 'Posodobi zdaj — sistemsko opozorilo št. :id označi kot rešeno',
        'remind_later' => 'Opomni me pozneje',
        'mark_resolved' => 'Označi kot rešeno',
        'mark_resolved_aria' => 'Označi kot rešeno — sistemsko opozorilo št. :id',
    ],

    'messages' => [
        'update_available' => 'Na voljo je posodobitev — Beatrax :version je pripravljen. Namestil se bo ob naslednjem zagonu.',
        'update_stale' => 'Uporabljaš različico :current — različica :latest je na voljo že 30 dni. Posodobi zdaj.',
        'update_critical' => 'Na voljo je kritična posodobitev — različica :version popravlja :summary. Namesti jo čim prej.',
        'backup_corrupt_with_path' => 'Varnostna kopija, zapisana ob :timestamp, ni prestala preverjanja celovitosti. Preglej :path. Reši to, preden se zaneseš na varnostne kopije.',
        'backup_corrupt_no_path' => 'Varnostna kopija, sprožena ob :timestamp, se je prekinila, preden je nastala kakršna koli datoteka — izvorna zbirka podatkov ni prestala preverjanja celovitosti. Reši to, preden se zaneseš na varnostne kopije.',

        'backup_overdue' => 'Najnovejša preverjena varnostna kopija je stara :hoursh. Zaženi <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> ali počakaj na načrtovani zagon ob 3.00.',
        'wal_mode_missing' => 'SQLite ni v načinu WAL (trenutno :mode). Sočasni zapisi lahko obtičijo. Za napotke zaženi <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'synchronous_misconfigured' => 'Raven synchronous v SQLite je :level (pričakovano NORMAL/1). Obnašanje glede trajnosti se lahko razlikuje od nastavitev. Za napotke zaženi <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>.',
        'reconnect_link' => 'Poveži znova →',
    ],
];
