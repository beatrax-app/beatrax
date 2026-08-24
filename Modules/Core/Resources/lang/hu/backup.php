<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Ez a telefon nem tud elmenteni egy fájlt, amit az alkalmazás átad neki, ezért a titkosított biztonsági mentés az asztali alkalmazásban készül. Párosítsd ezt az eszközt, hogy szinkronban maradjanak.',
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

        'intro_html' => 'Cseréld le a jelenlegi adatbázisodat egy titkosított biztonsági mentésre. A fájl visszafejtése és ellenőrzése megtörténik, mielőtt bármi megváltozna, és előbb pillanatkép készül a jelenlegi adataidról — ez azonban akkor is <strong class="text-slate-700 dark:text-slate-200">mindent felülír</strong>, ezért védett művelet.',
        'restored' => 'Visszaállítva. Töltsd újra az alkalmazást a visszaállított adatok megtekintéséhez.',
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
        'enter_passphrase' => 'Add meg a jelmondatot, amellyel a mentés titkosítva lett.',
        'unreadable' => 'A feltöltött fájlt nem sikerült beolvasni. Próbáld újra.',
    ],
];
