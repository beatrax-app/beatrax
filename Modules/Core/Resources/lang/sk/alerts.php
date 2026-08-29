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
        'oauth_scrub_set_failed' => 'Maskovanie tajomstiev OAuth nefunguje. Denníky a výňatky z auditu môžu až do ďalšieho úspešného načítania obsahovať nemaskované tokeny.',
        'oauth_reauth_required' => 'Tajomstvá OAuth sa presunuli do úložiska pre jednotlivých používateľov. Znova autorizujte Gmail a Microsoft, aby sa obnovilo skenovanie e-mailov. Starý súbor s tajomstvami bol premenovaný na :file pre prípad návratu.',
        'oauth_reconsent' => 'Znova pripojte svoj účet :provider',
        'auth_recovery_code_consumed' => 'Obnovovací kód použil používateľ :username.',
        'auth_recovery_code_failed' => 'Neúspešný pokus o obnovovací kód pre :username.',
        'auth_lock_hard_cap_reached' => 'Odhlásenie po príliš mnohých neúspešných pokusoch o PIN.',
        'open_banking_reconsent' => 'Znova pripojte svoju banku',
        'auth_lock_corrupted_key' => 'Váš PIN nedokáže na tomto zariadení odomknúť aplikáciu: uložený kľúč je nečitateľný. Prihláste sa heslom k účtu a nastavte nový PIN.',
        'sync_gdk_rewrap_failed' => 'Opätovné zabalenie zväzku kľúčov GDK po zmene prístupovej frázy zámku aplikácie zlyhalo — šifrované údaje môžu byť neobnoviteľné, kým sa zväzok znova nezabalí.',
        'worker_crashed' => 'Spracovanie na pozadí v Beatraxe sa neočakávane zastavilo. Importy a skenovanie e-mailov sú pozastavené. Reštartujte ich opätovným otvorením aplikácie.',
        'auth_lock_key_material_stranded' => 'Šifrovanie v pokoji je pre tento účet aktívne, ale kľúč k údajom už nedrží žiadny obal zámku aplikácie, takže každá šifrovaná poznámka, popis aj údaj o protistrane sa čítajú ako prázdne. Jedinou cestou späť je spárovanie so zariadením, ktoré kľúč stále drží.',
        'auth_lock_recovery_wrap_stale' => 'Heslo k účtu sa zmenilo bez toho, aby sa obnovovací obal zámku aplikácie znova zabalil, takže tým heslom už zámok neotvoríte. PIN áno. Znova prepojte heslo k účtu v nastaveniach zámku, kým PIN ešte poznáte — inak po zabudnutom PIN-e nezostane nič.',
        'reconnect_link' => 'Znova pripojiť →',
    ],
];
