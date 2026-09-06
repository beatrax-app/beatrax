<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Systémová upozornění',

    'actions' => [
        'download_and_install' => 'Stáhnout a nainstalovat',
        'download_and_install_aria' => 'Stáhnout a nainstalovat — označí systémové upozornění #:id jako vyřešené',
        'skip_version' => 'Přeskočit tuto verzi',
        'release_notes' => 'Poznámky k vydání →',
        'update_now' => 'Aktualizovat teď',
        'update_now_aria' => 'Aktualizovat teď — označí systémové upozornění #:id jako vyřešené',
        'remind_later' => 'Připomenout později',
        'mark_resolved' => 'Označit jako vyřešené',
        'mark_resolved_aria' => 'Označit jako vyřešené — systémové upozornění #:id',
        'assign_in_budgets' => 'Přidělit v Rozpočtech',
        'dismiss' => 'Zamítnout',
        'dismiss_aria' => 'Zamítnout — systémové upozornění #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'rozpočtová upozornění',
        'daily-triggers' => 'denní připomínky a souhrn',
    ],

    'messages' => [
        'update_available' => 'Je dostupná aktualizace — Beatrax :version. Nic se nestahuje, dokud instalaci nezvolíš; Beatrax se pak zavře a znovu otevře v nové verzi.',
        'update_stale' => 'Máš verzi :current — verze :latest je dostupná už 30 dní. Aktualizuj teď.',
        'update_critical' => 'Je dostupná kritická aktualizace — verze :version opravuje: :summary. Nainstaluj ji co nejdřív.',
        'backup_corrupt_with_path' => 'Záloha zapsaná v :timestamp neprošla kontrolou integrity. Podívej se na :path. Vyřeš to dřív, než se na zálohy spolehneš.',
        'backup_corrupt_no_path' => 'Záloha spuštěná v :timestamp skončila dřív, než vznikl jakýkoli soubor — zdrojová databáze neprošla kontrolou integrity. Vyřeš to dřív, než se na zálohy spolehneš.',
        'backup_write_failed' => 'Záloha zahájená v :timestamp nedoběhla — databáze prošla kontrolami, ale soubory zálohy nešlo zapsat. Zkontroluj volné místo a oprávnění složky se zálohami.',
        'backup_restore_failed' => 'Obnovení zahájené v :timestamp nedoběhlo. Tvá předchozí data byla předtím uložena do :snapshot.',

        'backup_overdue' => 'Nejnovější ověřená záloha je :hoursh stará. Beatrax si tuto zálohu dělá sám, jednou denně, dokud je aplikace otevřená — ručně není co spouštět. Pokud zůstane takhle stará, aplikace nebyla otevřená, když měl denní běh přijít.',
        'backup_none_found' => 'Ve složce se zálohami nebyla nalezena žádná ověřená záloha. Beatrax si tuto zálohu dělá sám, jednou denně, dokud je aplikace otevřená — ručně není co spouštět.',
        'wal_mode_missing' => 'Databáze není v režimu WAL (aktuálně :mode), takže ukládání se může pozastavit, když běží úloha na pozadí. Beatrax nastavuje WAL při každém spuštění, takže restart to obvykle vyřeší.',
        'synchronous_misconfigured' => 'Úroveň trvanlivosti databáze je :level místo očekávané NORMAL. Beatrax ji nastavuje při každém spuštění, takže restart to obvykle vyřeší.',
        'oauth_scrub_set_failed' => 'Maskování tajemství OAuth je mimo provoz. Logy a výňatky z auditu mohou obsahovat nemaskované tokeny až do dalšího úspěšného načtení.',
        'oauth_reauth_required' => 'Tajemství OAuth byla přesunuta do úložiště jednotlivých uživatelů. Znovu autorizujte Gmail a Microsoft, abyste obnovili skenování e-mailů. Starý soubor s tajemstvími byl přejmenován na :file pro případ návratu.',
        'oauth_reconsent' => 'Znovu připojte svůj účet :provider',
        'auth_recovery_code_consumed' => 'Záložní kód použil uživatel :username.',
        'auth_recovery_code_failed' => 'Neúspěšný pokus o zadání záložního kódu pro :username.',
        'auth_lock_hard_cap_reached' => 'Odhlášení po příliš mnoha neúspěšných pokusech o PIN.',
        'open_banking_reconsent' => 'Znovu připojte svou banku',
        'open_banking_nothing_imported' => 'Vaše banka poslala transakce, ale Beatrax nedokázal zaznamenat ani jednu, takže do vaší evidence se nic nedostalo. Otevřete nastavení Open banking a zjistěte proč.',
        'auth_lock_corrupted_key' => 'Váš PIN nedokáže na tomto zařízení odemknout aplikaci: uložený klíč je nečitelný. Přihlaste se heslem k účtu a nastavte nový PIN.',
        'sync_gdk_rewrap_failed' => 'Opětovné zabalení klíčenky GDK po změně přístupové fráze zámku aplikace selhalo — šifrovaná data mohou být neobnovitelná, dokud klíčenku znovu nezabalíte.',
        'worker_crashed' => 'Zpracování na pozadí v Beatraxu se neočekávaně zastavilo. Importy a skenování e-mailů jsou pozastaveny. Restartujte je opětovným otevřením aplikace.',
        'auth_lock_key_material_stranded' => 'Šifrování v klidu je pro tento účet aktivní, ale klíč k datům už nedrží žádný obal zámku aplikace, takže každá šifrovaná poznámka, popis i údaj o protistraně se čtou jako prázdné. Obnovte šifrovanou zálohu pořízenou v době, kdy klíč ještě fungoval, nebo tento účet znovu nastavte na zařízení, které jej stále drží.',
        'auth_lock_recovery_wrap_stale' => 'Heslo k účtu se změnilo, aniž by byl obnovovací obal zámku aplikace znovu zabalen, takže tímto heslem už zámek neotevřete. PIN ano. Znovu propojte heslo k účtu v nastavení zámku, dokud PIN ještě znáte — jinak po zapomenutém PINu nezůstane nic.',
        'reconnect_link' => 'Znovu připojit →',
        // i18n-review: cs · pots_category_link_retired — "obálka" is this app's
        // word for a budget envelope and for a savings pot at once, so the pots are
        // named "spořicí obálky" here to keep the two apart. A Czech reader settles
        // whether that reads or whether the pots want a different noun.
        'pots_category_link_retired' => 'Rozpočtování po obálkách nahradilo spořicí obálky navázané na kategorii. Částka :amount z :count archivované spořicí obálky je opět nepřiřazená a čeká, až ji přiřadíte.|Rozpočtování po obálkách nahradilo spořicí obálky navázané na kategorii. Částka :amount z :count archivovaných spořicích obálek je opět nepřiřazená a čeká, až ji přiřadíte.|Rozpočtování po obálkách nahradilo spořicí obálky navázané na kategorii. Částka :amount z :count archivovaných spořicích obálek je opět nepřiřazená a čeká, až ji přiřadíte.',
        'notifications_deferred_pass_failed' => 'Beatrax na tomto zařízení nedokázal sestavit :pass, takže některá mohou chybět. Zkusí to znovu při každém otevření aplikace.',
    ],
];
