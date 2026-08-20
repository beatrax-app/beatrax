<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Párosított eszköz',
    'page_title' => 'Eszköz párosítása',

    'scan_heading' => 'Ennek az eszköznek a párosítása',
    'scan_subtitle' => 'Irányítsd a kamerát a másik eszközön megjelenő kódra.',
    'camera_permission_pending' => 'A kamerahozzáférés ki van kapcsolva. Engedélyezd a Beatrax számára az eszközbeállításokban, majd próbáld újra.',
    'open_camera' => 'Kamera megnyitása',
    'opening_camera' => 'Várakozás a kamerahozzáférésre…',
    'close_camera' => 'Kamera bezárása',
    'viewfinder_aria' => 'Kameranéző — irányítsd a másik eszközödön látható kódra',
    'viewfinder_idle' => 'A kamera ki van kapcsolva. Nyisd meg a másik eszközödön látható kód beolvasásához.',
    'scan_prompt' => 'Olvasd be a másik eszközödön látható kódot',
    'enter_code_instead' => 'Inkább kód megadása',

    'enter_heading' => 'Add meg a kódot',
    'camera_off' => 'A kamerahozzáférés ki van kapcsolva. Helyette add meg a másik eszközön látható kódot.',
    'word_code_aria' => 'Add meg a másik eszközön látható szókódot',
    'submit_code' => 'Kód elküldése',
    'cancel' => 'Mégse',

    'confirm_heading' => 'Hasonlítsd össze ezeket a szavakat a másik eszközzel',
    'safety_words_aria' => 'Biztonsági számszavak: :words',
    'confirm_body' => 'Mindkét eszköznek pontosan ugyanazokat a szavakat kell mutatnia. Ha eltérnek, koppints a Mégse gombra — közbeékelődéses (man-in-the-middle) támadás lehet folyamatban.',
    'awaiting_peer' => 'Várakozás a másik eszköz megerősítésére...',
    'confirm_match' => 'Megerősítés — egyeznek',

    'success_heading' => 'Eszköz párosítva',
    'success_body' => 'Ez az eszköz mostantól megbízható. Az adataid szinkronizálódnak, amint csatlakozol.',
    'done' => 'Kész',

    'errors' => [
        'relay_unreachable' => 'A másik eszköz nem érhető el. Győződj meg róla, hogy mindkettő ugyanazon a hálózaton van, és a szinkronizálás be van kapcsolva az asztali gépen.',
        'invalid_code' => 'Ez a kód érvénytelen vagy lejárt. Kérj újat a másik eszköztől.',
        'identity_locked' => 'Az eszközazonosságod zárolva van. Oldd fel az alkalmazást, és próbáld újra.',
        'identity_needs_lock' => 'Először állítsa be az alkalmazászárat — ez védi az eszköz identitását.',
    ],
];
