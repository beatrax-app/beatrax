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
        'auth_recovery_code_consumed' => 'Kôd za oporavak upotrijebio je :username.',
        'auth_recovery_code_failed' => 'Neuspio pokušaj kôda za oporavak za :username.',
        'auth_lock_hard_cap_reached' => 'Odjava nakon previše neuspjelih pokušaja PIN-a.',
        'open_banking_reconsent' => 'Ponovno povežite svoju banku',
        'auth_lock_corrupted_key' => 'Vaš PIN ne može otključati aplikaciju na ovom uređaju: pohranjeni ključ nije čitljiv. Prijavite se lozinkom računa kako biste postavili novi PIN.',
        'sync_gdk_rewrap_failed' => 'Ponovno omatanje GDK privjeska ključeva nije uspjelo nakon promjene zaporke zaključavanja aplikacije — šifrirani podaci možda se neće moći vratiti dok se privjesak ponovno ne omota.',
        'worker_crashed' => 'Beatraxova pozadinska obrada neočekivano je prestala. Uvozi i skeniranja e-pošte su pauzirani. Ponovno otvorite aplikaciju da biste je pokrenuli.',
        'auth_lock_key_material_stranded' => 'Šifriranje u mirovanju aktivno je za ovaj račun, ali nijedan omotač zaključavanja aplikacije više ne drži ključ podataka, pa se svaka šifrirana bilješka, opis i podatak o drugoj strani čitaju kao prazni. Uparivanje s uređajem koji još drži ključ jedini je put natrag.',
        'auth_lock_recovery_wrap_stale' => 'Lozinka računa promijenjena je bez ponovnog omatanja omotača za oporavak zaključavanja aplikacije, pa ta lozinka više ne otključava aplikaciju. PIN i dalje otključava. Ponovno povežite lozinku računa u postavkama zaključavanja dok je PIN još poznat — inače zaboravljeni PIN ne ostavlja ništa iza sebe.',
        'reconnect_link' => 'Poveži ponovno →',
    ],
];
