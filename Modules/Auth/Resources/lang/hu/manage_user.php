<?php

declare(strict_types=1);

return [
    'page_title' => ':name kezelése · Beatrax',
    'heading' => ':name kezelése',
    'subtitle' => 'Nézd meg, állítsd vissza vagy generáld újra ennek a felhasználónak a kódjait.',

    'set_password' => [
        'heading' => 'Új jelszó beállítása ehhez a felhasználóhoz',
        'description' => 'A következő bejelentkezésekor jelszót kell választania.',
        'open' => 'Új jelszó beállítása ehhez a felhasználóhoz',
        'body' => 'Állíts be új jelszót a(z) :name felhasználónak. A következő bejelentkezésekor jelszót kell választania.',
        'label' => 'Új jelszó',
        'submit' => 'Jelszó beállítása',
        'cancel' => 'Mégse',
    ],

    'regenerate' => [
        'heading' => 'Helyreállítási kódok újragenerálása ehhez a felhasználóhoz',
        'description' => 'A régi kódok érvénytelenné válnak.',
        'open' => 'Helyreállítási kódok újragenerálása ehhez a felhasználóhoz',
        'body' => 'A meglévő, fel nem használt kódjai megszűnnek működni. A 10 új kódot egyszer látod, és át tudod adni.',
        'confirm_label' => 'A folytatáshoz írd be a felhasználónevet',
        'submit' => 'Kódok újragenerálása',
        'keep' => 'Jelenlegi kódok megtartása',
        'download' => 'Letöltés .txt fájlként',
    ],

    'error_min_length' => 'Használj legalább 12 karaktert.',
    'password_set' => 'A(z) :name jelszava beállítva. A következő bejelentkezésekor jelszót kell választania.',
    'codes_regenerated' => 'Tíz új helyreállítási kód generálva a(z) :name felhasználónak.',
];
