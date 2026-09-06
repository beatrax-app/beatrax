<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systémové upozornenia',

    'actions' => [
        'download_and_install' => 'Stiahnuť a nainštalovať',
        'download_and_install_aria' => 'Stiahnuť a nainštalovať — označí systémové upozornenie #:id ako vyriešené',
        'skip_version' => 'Preskočiť túto verziu',
        'release_notes' => 'Poznámky k vydaniu →',
        'update_now' => 'Aktualizovať teraz',
        'update_now_aria' => 'Aktualizovať teraz — označí systémové upozornenie #:id ako vyriešené',
        'remind_later' => 'Pripomenúť neskôr',
        'mark_resolved' => 'Označiť ako vyriešené',
        'mark_resolved_aria' => 'Označiť ako vyriešené — systémové upozornenie #:id',
        'assign_in_budgets' => 'Prideliť v Rozpočtoch',
        'dismiss' => 'Zamietnuť',
        'dismiss_aria' => 'Zamietnuť — systémové upozornenie #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'rozpočtové upozornenia',
        'daily-triggers' => 'denné pripomienky a súhrn',
    ],

    'messages' => [
        'update_available' => 'Je dostupná aktualizácia — Beatrax :version. Kým sa nerozhodneš inštalovať, nič sa nesťahuje; Beatrax sa potom zavrie a znova otvorí v novej verzii.',
        'update_refused' => 'Beatrax stiahol verziu :version a odmietol ju nainštalovať — súbor nezodpovedal podpisu vydavateľa, takže sa na tomto zariadení nič nezmenilo. Môže to spôsobiť poškodené stiahnutie. Ak sa to opakuje, neinštaluj Beatrax z tohto zdroja.',
        'update_stale' => 'Používaš verziu :current — verzia :latest je dostupná už 30 dní. Aktualizuj teraz.',
        'update_critical' => 'Je dostupná kritická aktualizácia — verzia :version opravuje: :summary. Nainštaluj ju čo najskôr.',
        'backup_corrupt_with_path' => 'Záloha zapísaná o :timestamp neprešla kontrolou integrity. Skontroluj :path. Vyrieš to skôr, než sa na zálohy spoľahneš.',
        'backup_corrupt_no_path' => 'Záloha spustená o :timestamp sa prerušila skôr, než vznikol akýkoľvek súbor — zdrojová databáza neprešla kontrolou integrity. Vyrieš to skôr, než sa na zálohy spoľahneš.',
        'backup_write_failed' => 'Záloha spustená o :timestamp sa nedokončila — databáza prešla kontrolami, ale súbory zálohy sa nepodarilo zapísať. Skontroluj voľné miesto a oprávnenia priečinka so zálohami.',
        'backup_restore_failed' => 'Obnovenie spustené o :timestamp sa nedokončilo. Tvoje predchádzajúce údaje boli predtým uložené do :snapshot.',

        'backup_overdue' => 'Najnovšia overená záloha je :hoursh stará. Beatrax si túto zálohu robí sám, raz denne, kým je aplikácia otvorená — ručne nie je čo spúšťať. Ak zostane takto stará, aplikácia nebola otvorená, keď mal prísť denný beh.',
        'backup_none_found' => 'V priečinku so zálohami sa nenašla žiadna overená záloha. Beatrax si túto zálohu robí sám, raz denne, kým je aplikácia otvorená — ručne nie je čo spúšťať.',
        'wal_mode_missing' => 'Databáza nie je v režime WAL (aktuálne :mode), takže ukladanie sa môže pozastaviť, kým beží úloha na pozadí. Beatrax nastavuje WAL pri každom spustení, takže reštart to zvyčajne vyrieši.',
        'synchronous_misconfigured' => 'Úroveň trvanlivosti databázy je :level namiesto očakávanej NORMAL. Beatrax ju nastavuje pri každom spustení, takže reštart to zvyčajne vyrieši.',
        'oauth_scrub_set_failed' => 'Maskovanie tajomstiev OAuth nefunguje. Denníky a výňatky z auditu môžu až do ďalšieho úspešného načítania obsahovať nemaskované tokeny.',
        'oauth_reauth_required' => 'Tajomstvá OAuth sa presunuli do úložiska pre jednotlivých používateľov. Znova autorizujte Gmail a Microsoft, aby sa obnovilo skenovanie e-mailov. Starý súbor s tajomstvami bol premenovaný na :file pre prípad návratu.',
        'oauth_reconsent' => 'Znova pripojte svoj účet :provider',
        'auth_recovery_code_consumed' => 'Obnovovací kód použil používateľ :username.',
        'auth_recovery_code_failed' => 'Neúspešný pokus o obnovovací kód pre :username.',
        'auth_lock_hard_cap_reached' => 'Odhlásenie po príliš mnohých neúspešných pokusoch o PIN.',
        'open_banking_reconsent' => 'Znova pripojte svoju banku',
        'open_banking_nothing_imported' => 'Tvoja banka poslala transakcie, ale Beatrax nedokázal zaznamenať ani jednu, takže do tvojej evidencie sa nič nedostalo. Otvor nastavenia Open banking a zisti prečo.',
        'auth_lock_corrupted_key' => 'Váš PIN nedokáže na tomto zariadení odomknúť aplikáciu: uložený kľúč je nečitateľný. Prihláste sa heslom k účtu a nastavte nový PIN.',
        'sync_gdk_rewrap_failed' => 'Opätovné zabalenie zväzku kľúčov GDK po zmene prístupovej frázy zámku aplikácie zlyhalo — šifrované údaje môžu byť neobnoviteľné, kým sa zväzok znova nezabalí.',
        'worker_crashed' => 'Spracovanie na pozadí v Beatraxe sa neočakávane zastavilo. Importy a skenovanie e-mailov sú pozastavené. Reštartujte ich opätovným otvorením aplikácie.',
        'auth_lock_key_material_stranded' => 'Šifrovanie v pokoji je pre tento účet aktívne, ale kľúč k údajom už nedrží žiadny obal zámku aplikácie, takže každá šifrovaná poznámka, popis aj údaj o protistrane sa čítajú ako prázdne. Obnov zašifrovanú zálohu vytvorenú v čase, keď kľúč ešte fungoval, alebo tento účet nastav znova na zariadení, ktoré ho stále drží.',
        'auth_lock_recovery_wrap_stale' => 'Heslo k účtu sa zmenilo bez toho, aby sa obnovovací obal zámku aplikácie znova zabalil, takže tým heslom už zámok neotvoríte. PIN áno. Znova prepojte heslo k účtu v nastaveniach zámku, kým PIN ešte poznáte — inak po zabudnutom PIN-e nezostane nič.',
        'reconnect_link' => 'Znova pripojiť →',
        // i18n-review: sk · pots_category_link_retired — "obálka" is this app's
        // word for a budget envelope and for a savings pot at once, so the pots are
        // named "sporiace obálky" here to keep the two apart. A Slovak reader settles
        // whether that reads or whether the pots want a different noun.
        'pots_category_link_retired' => 'Rozpočtovanie po obálkach nahradilo sporiace obálky naviazané na kategóriu. Suma :amount z :count archivovanej sporiacej obálky je opäť nepriradená a čaká, kým ju prideliš.|Rozpočtovanie po obálkach nahradilo sporiace obálky naviazané na kategóriu. Suma :amount z :count archivovaných sporiacich obálok je opäť nepriradená a čaká, kým ju prideliš.|Rozpočtovanie po obálkach nahradilo sporiace obálky naviazané na kategóriu. Suma :amount z :count archivovaných sporiacich obálok je opäť nepriradená a čaká, kým ju prideliš.',
        'notifications_deferred_pass_failed' => 'Beatrax na tomto zariadení nedokázal vypočítať :pass, takže niektoré môžu chýbať. Skúsi to znova pri každom otvorení aplikácie.',
    ],
];
