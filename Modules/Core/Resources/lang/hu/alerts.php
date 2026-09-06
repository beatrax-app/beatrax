<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Rendszerüzenetek',

    'actions' => [
        'download_and_install' => 'Letöltés és telepítés',
        'download_and_install_aria' => 'Letöltés és telepítés — a #:id rendszerüzenetet megoldottnak jelöli',
        'skip_version' => 'Verzió kihagyása',
        'release_notes' => 'Kiadási megjegyzések →',
        'update_now' => 'Frissítés most',
        'update_now_aria' => 'Frissítés most — a #:id rendszerüzenetet megoldottnak jelöli',
        'remind_later' => 'Emlékeztess később',
        'mark_resolved' => 'Megjelölés megoldottként',
        'mark_resolved_aria' => 'Megjelölés megoldottként — #:id rendszerüzenet',
        'assign_in_budgets' => 'Kiosztás a Költségvetésekben',
        'dismiss' => 'Elvetés',
        'dismiss_aria' => 'Elvetés — #:id rendszerüzenet',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'a költségvetési értesítéseket',
        'daily-triggers' => 'a napi emlékeztetőket és az összefoglalót',
    ],

    'messages' => [
        'update_available' => 'Elérhető frissítés — Beatrax :version. Semmi nem töltődik le, amíg nem választod a telepítést; a Beatrax ezután bezárul, és az új verzióval nyílik meg újra.',
        'update_stale' => 'A(z) :current verziót használod — a(z) :latest verzió 30 napja elérhető. Frissíts most.',
        'update_critical' => 'Kritikus frissítés érhető el — a(z) :version verzió javítja ezt: :summary. Telepítsd mielőbb.',
        'backup_corrupt_with_path' => 'A(z) :timestamp időpontban írt biztonsági mentés megbukott az integritás-ellenőrzésen. Vizsgáld meg ezt: :path. Oldd meg, mielőtt a mentésekre támaszkodnál.',
        'backup_corrupt_no_path' => 'A(z) :timestamp időpontban megkísérelt biztonsági mentés megszakadt, mielőtt bármilyen fájl elkészült volna — a forrásadatbázis megbukott az integritás-ellenőrzésen. Oldd meg, mielőtt a mentésekre támaszkodnál.',
        'backup_write_failed' => 'A(z) :timestamp időpontban indított biztonsági mentés nem fejeződött be — az adatbázis megfelelt az ellenőrzéseken, a mentés fájljait nem sikerült kiírni. Ellenőrizd a szabad helyet és a mentési mappa jogosultságait.',
        'backup_restore_failed' => 'A(z) :timestamp időpontban indított visszaállítás nem fejeződött be. A korábbi adataid előtte a(z) :snapshot fájlba kerültek.',

        'backup_overdue' => 'A legutóbbi ellenőrzött biztonsági mentés :hoursh régi. A Beatrax ezt a mentést maga készíti, naponta egyszer, amíg az alkalmazás nyitva van — kézzel nincs mit futtatni. Ha ennyire régi marad, az alkalmazás nem volt nyitva, amikor a napi futás esedékes lett.',
        'backup_none_found' => 'A biztonsági mentések mappájában nem található ellenőrzött mentés. A Beatrax ezt a mentést maga készíti, naponta egyszer, amíg az alkalmazás nyitva van — kézzel nincs mit futtatni.',
        'wal_mode_missing' => 'Az SQLite nem WAL módban fut (jelenleg: :mode). Az egyidejű írások megakadhatnak. Útmutatásért futtasd a <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> parancsot.',
        'synchronous_misconfigured' => 'Az SQLite synchronous szintje :level (elvárt: NORMAL/1). A tartósság viselkedése eltérhet a beállítottól. Útmutatásért futtasd a <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> parancsot.',
        'oauth_scrub_set_failed' => 'Az OAuth-titkok kitakarása nem működik. A naplók és az auditrészletek a következő sikeres betöltésig kitakaratlan tokeneket tartalmazhatnak.',
        'oauth_reauth_required' => 'Az OAuth-titkok felhasználónkénti tárolóba kerültek. Engedélyezze újra a Gmailt és a Microsoftot az e-mailek vizsgálatának folytatásához. A régi titokfájl visszaállítás céljából :file névre lett átnevezve.',
        'oauth_reconsent' => 'Csatlakoztassa újra a(z) :provider fiókját',
        'auth_recovery_code_consumed' => ':username felhasználó helyreállítási kódot használt.',
        'auth_recovery_code_failed' => 'Sikertelen helyreállítási kód kísérlet :username felhasználónál.',
        'auth_lock_hard_cap_reached' => 'Kijelentkezés túl sok sikertelen PIN-kísérlet után.',
        'open_banking_reconsent' => 'Csatlakoztassa újra a bankját',
        'open_banking_nothing_imported' => 'A bankja tranzakciókat küldött, de a Beatrax egyiket sem tudta rögzíteni, így semmi sem került a nyilvántartásába. Nyissa meg a Nyílt bankolás beállításait, hogy lássa miért.',
        'auth_lock_corrupted_key' => 'A PIN-kódja nem tudja feloldani az alkalmazászárat ezen az eszközön: a tárolt kulcs olvashatatlan. Jelentkezzen be a fiókjelszavával, és állítson be új PIN-kódot.',
        'sync_gdk_rewrap_failed' => 'A GDK-kulcstartó újracsomagolása sikertelen volt az alkalmazászár jelmondatának módosítása után — a titkosított adatok visszaállíthatatlanok lehetnek, amíg a kulcstartót újra nem csomagolják.',
        'worker_crashed' => 'A Beatrax háttérfeldolgozása váratlanul leállt. Az importálások és az e-mail-vizsgálatok szünetelnek. Az újraindításhoz nyissa meg újra az alkalmazást.',
        'auth_lock_key_material_stranded' => 'A nyugalmi állapotú titkosítás aktív ehhez a fiókhoz, de már egyetlen alkalmazászár-burok sem tartja az adatkulcsot, ezért minden titkosított jegyzet, leírás és partneradat üresként olvasható. Állítson vissza egy titkosított biztonsági mentést, amely még a kulcs működése idején készült, vagy állítsa be újra ezt a fiókot egy olyan eszközön, amely még őrzi a kulcsot.',
        'auth_lock_recovery_wrap_stale' => 'A fiók jelszava úgy változott meg, hogy az alkalmazászár helyreállítási burka nem lett újracsomagolva, ezért az a jelszó már nem nyitja az alkalmazászárat. A PIN-kód még igen. Kösse össze újra a fiókjelszót az alkalmazászár beállításaiban, amíg a PIN-kód ismert — különben egy elfelejtett PIN mögött nem marad semmi.',
        'reconnect_link' => 'Újracsatlakozás →',
        'pots_category_link_retired' => 'A borítékos költségvetés felváltotta a kategóriához kötött perselyeket. A(z) :count archivált perselyből felszabadult :amount ismét kiosztatlan, és arra vár, hogy kiossza.|A borítékos költségvetés felváltotta a kategóriához kötött perselyeket. A(z) :count archivált perselyből felszabadult :amount ismét kiosztatlan, és arra vár, hogy kiossza.',
        'notifications_deferred_pass_failed' => 'A Beatrax ezen az eszközön nem tudta kiszámítani :pass, ezért néhány hiányozhat. Az alkalmazás minden megnyitásakor újra próbálkozik.',
    ],
];
