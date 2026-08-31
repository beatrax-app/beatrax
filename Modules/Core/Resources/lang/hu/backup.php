<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Ez az alkalmazás nem tud fájlt átadni az eszközödnek, ezért a titkosított biztonsági mentés az asztali alkalmazásban készül. Párosítsd ezt az eszközt, hogy szinkronban maradjanak.',
        'unavailable' => 'A titkosított biztonsági mentések az asztali (SQLite) változatban érhetők el. Kiszolgálón futó adatbázis esetén használd az adatbázis saját mentési eszközeit.',
        'intro' => 'Tölts le a teljes adatbázisodról egy jelmondattal titkosított másolatot — nyugodtan tarthatod külső meghajtón vagy felhőtárhelyen, mert jelmondat nélkül olvashatatlan (kvantumbiztos XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Jelmondat',
        'confirm_passphrase' => 'Jelmondat megerősítése',
        'keep_safe' => 'Őrizd meg jól a jelmondatot — nélküle a mentés nem állítható vissza.',
        'submit' => 'Titkosított mentés letöltése',
        'preparing' => 'Előkészítés…',
    ],

    'restore' => [
        'heading' => 'Visszaállítás biztonsági mentésből',

        'intro_html' => 'Cseréld le a jelenlegi adatbázisodat egy titkosított biztonsági mentésre. A fájl visszafejtése és ellenőrzése megtörténik, mielőtt bármi megváltozna, és előbb pillanatkép készül a jelenlegi adataidról — ez azonban akkor is <strong class="text-slate-700 dark:text-slate-200">mindent felülír</strong>, ezért védett művelet. Ki fogsz jelentkezni, mert a bejelentkezésed is az adatbázisban van.',
        'restored' => 'A biztonsági mentés visszaállt. Jelentkezzen be azzal a felhasználónévvel és jelszóval, amely a készítésekor érvényes volt.',
        'snapshot_saved_prefix' => 'A korábbi adataidról készült pillanatkép ide mentve',
        'file_label' => 'Titkosított biztonsági mentés (.enc)',
        'uploading' => 'Feltöltés…',
        'passphrase' => 'Jelmondat',
        'confirm_prefix' => 'Írd be',
        'confirm_suffix' => 'a megerősítéshez',
        'submit' => 'Visszaállítás (felülírja a jelenlegi adatokat)',
        'restoring' => 'Visszaállítás…',
    ],

    'errors' => [
        'passphrase_min' => 'Használj legalább :min karakteres jelmondatot.|Használj legalább :min karakteres jelmondatot.',
        'passphrase_mismatch' => 'A két jelmondat nem egyezik.',
        'download_sqlite_only' => 'A titkosított letöltés csak az SQLite változatban érhető el.',
        'create_failed' => 'A biztonsági mentés nem készült el: :message',
        'confirm_phrase' => 'A megerősítéshez írd be: :phrase — ez lecseréli a jelenlegi adataidat.',
        'choose_file' => 'Válassz egy titkosított mentésfájlt (.enc) a visszaállításhoz.',
        'upload_failed' => 'A fájl feltöltése nem fejeződött be. Lehet, hogy túl nagy ehhez az eszközhöz — az asztali alkalmazásban nagyobb biztonsági mentés is visszaállítható.',
        'enter_passphrase' => 'Add meg a jelmondatot, amellyel a mentés titkosítva lett.',
        'unreadable' => 'A feltöltött fájlt nem sikerült beolvasni. Próbáld újra.',
        'restore_wrong_passphrase' => 'Ez a jelmondat nem nyitotta meg ezt a biztonsági mentést, és semmi sem változott. Írd be újra, és próbáld meg ismét. Ha biztosan jó, a fájl a létrehozása óta módosult — akkor egy másik másolatból állíts vissza.',
        'restore_not_a_backup' => 'Ez a fájl nem titkosított Beatrax biztonsági mentés, így nincs mit visszaállítani, és semmi sem változott. Válaszd azt az .enc fájlt, amelyet az alkalmazás a mentéskor írt.',
        'restore_contents_unreadable' => 'A biztonsági mentés megnyílt, de a benne lévő adatbázis sérült, ezért nem állt vissza, és semmi sem változott. Állíts vissza egy korábbi mentésből.',
        'restore_could_not_read' => 'A mentésfájlt nem sikerült beolvasni, így a visszaállítás nem futott le, és semmi sem változott. Ellenőrizd, van-e szabad hely az eszközön, és próbáld újra.',
        'restore_not_supported' => 'A visszaállítás abban a kiadásban működik, amely egyetlen fájlban tartja az adatait, és ez nem olyan, így semmi sem változott. Kiszolgálói adatbázisnál használd annak saját visszaállító eszközeit.',
        'restore_failed' => 'A visszaállítás nem futott le, és semmi sem változott. Próbáld újra — ha továbbra is hibázik, az alkalmazás naplója rögzíti, mi állította meg.',
    ],
];
