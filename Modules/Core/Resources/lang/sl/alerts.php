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
        'oauth_scrub_set_failed' => 'Prikrivanje skrivnosti OAuth ne deluje. Dnevniki in izvlečki revizije lahko do naslednjega uspešnega nalaganja vsebujejo neprikrite žetone.',
        'oauth_reauth_required' => 'Skrivnosti OAuth so bile premaknjene v shrambo za posameznega uporabnika. Znova avtorizirajte Gmail in Microsoft, da se pregledovanje e-pošte nadaljuje. Stara datoteka s skrivnostmi je bila zaradi vrnitve preimenovana v :file.',
        'oauth_reconsent' => 'Znova povežite svoj :provider',
        'auth_recovery_code_consumed' => 'Kodo za obnovitev je uporabil :username.',
        'auth_recovery_code_failed' => 'Neuspel poskus kode za obnovitev za :username.',
        'auth_lock_hard_cap_reached' => 'Odjava po preveč neuspelih poskusih PIN.',
        'open_banking_reconsent' => 'Znova povežite svojo banko',
        'auth_lock_corrupted_key' => 'Vaš PIN v tej napravi ne more odkleniti aplikacije: shranjeni ključ ni berljiv. Prijavite se z geslom računa in nastavite nov PIN.',
        'sync_gdk_rewrap_failed' => 'Ponovno ovijanje obeska GDK po spremembi geselne fraze zaklepanja aplikacije ni uspelo — šifriranih podatkov morda ne bo mogoče obnoviti, dokler obesek ni ponovno ovit.',
        'worker_crashed' => 'Beatraxova obdelava v ozadju se je nepričakovano ustavila. Uvozi in pregledovanje e-pošte so zaustavljeni. Znova odprite aplikacijo, da jo zaženete.',
        'auth_lock_key_material_stranded' => 'Šifriranje v mirovanju je za ta račun aktivno, vendar noben ovoj zaklepanja aplikacije ne hrani več ključa podatkov, zato se vsaka šifrirana opomba, opis in podatek o nasprotni stranki prebere kot prazna. Edina pot nazaj je seznanitev z napravo, ki ključ še ima.',
        'auth_lock_recovery_wrap_stale' => 'Geslo računa se je spremenilo, ne da bi bil ovoj za obnovitev zaklepanja aplikacije ponovno ovit, zato to geslo ne odklene več aplikacije. PIN jo še vedno odklene. Znova povežite geslo računa v nastavitvah zaklepanja, dokler je PIN še znan — sicer za pozabljenim PIN-om ne ostane nič.',
        'reconnect_link' => 'Poveži znova →',
    ],
];
