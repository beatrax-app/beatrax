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
    'camera_off_no_search' => 'A kamerahozzáférés ki van kapcsolva, és a másik eszköz keresése a hálózaton iPhone-on még nem működik — a begépelt kódnak így nincs mivel megtalálnia. Kapcsold vissza a kamerahozzáférést a Beatrax számára az eszközbeállításokban, majd olvasd be a másik eszköz kódját.',
    'no_search' => 'A másik eszköz keresése a hálózaton iPhone-on még nem működik, így a begépelt kódnak nincs mit megtalálnia. Olvasd be helyette a kódot a kamerával — a kamerának nem kell keresnie a hálózaton.',
    'word_code_aria' => 'Add meg a másik eszközön látható szókódot',
    'submit_code' => 'Kód elküldése',
    'cancel' => 'Mégse',
    'skip_import' => 'Folytatás importálás nélkül',

    'confirm_heading' => 'Hasonlítsd össze ezeket a szavakat a másik eszközzel',
    'safety_words_aria' => 'Biztonsági számszavak: :words',
    'confirm_body' => 'Mindkét eszköznek pontosan ugyanazokat a szavakat kell mutatnia. Ha eltérnek, koppints a Mégse gombra — közbeékelődéses (man-in-the-middle) támadás lehet folyamatban.',
    'awaiting_peer' => 'Várakozás a másik eszköz megerősítésére...',
    'confirm_match' => 'Megerősítés — egyeznek',

    'success_heading' => 'Eszköz párosítva',
    'success_body' => 'Ez az eszköz mostantól megbízható. Az adataid szinkronizálódnak, amint csatlakozol.',
    'encryption_incomplete' => 'Az eszköz párosítva van, de a rajta tárolt adatok titkosítása nem fejeződött be. Az adatok tárolása még nem titkosított.',
    'done' => 'Kész',

    'errors' => [
        'relay_unreachable' => 'A másik eszköz nem érhető el. Győződj meg róla, hogy mindkettő ugyanazon a hálózaton van, és a szinkronizálás be van kapcsolva az asztali gépen.',
        'no_road_home' => 'Ez az eszköz nem tud keresni a hálózaton, és a beolvasott kód nem tartalmaz címet a másik eszközhöz. Kérd meg, hogy mutasson új kódot, és olvasd be azt.',
        'invalid_code' => 'Ez a kód érvénytelen vagy lejárt. Kérj újat a másik eszköztől.',
        'already_under_way' => 'Ez az eszköz már elfogadta a kódot, és a másik eszköz megerősítésére vár. Ha nem érkezik meg, kérj új kódot, és azt használd.',
        'vouched_but_refused' => 'A másik eszköznél még megvan ez a kód, de ez az eszköz nem tudta elfogadni. Kérj tőle új kódot, és azt használd.',
        'code_incomplete' => 'Ez a kód nem teljes. Vesd össze a másik eszközzel, és add meg az egészet.',
        'code_not_accepted' => 'A hálózaton egyetlen eszköz sem fogadta el ezt a kódot. Ellenőrizd a kódot, és hogy a másik eszköz még mutatja-e.',
        'no_peer_answered' => 'Ezen a hálózaton semmi sem válaszolt erre a kódra. Ellenőrizd, hogy fut-e a szinkronizálás a másik eszközön, vagy olvasd be a kódját a kamerával — a kamerának nem kell keresnie a hálózaton.',
        'no_peer_answered_ios' => 'Ezen a hálózaton semmi sem válaszolt erre a kódra. A másik eszköz keresése a hálózaton iPhone-on még nem működik, ezért olvasd be a kódját a kamerával.',
        'no_peer_answered_camera_off' => 'Ezen a hálózaton semmi sem válaszolt erre a kódra. A másik eszköz keresése a hálózaton iPhone-on még nem működik, a kamerahozzáférés pedig ki van kapcsolva — kapcsold ezért vissza a kamerahozzáférést a Beatrax számára az eszközbeállításokban, majd olvasd be a másik eszköz kódját.',
        'rate_limited' => 'Túl sok próbálkozás. Várj egy percet, és próbáld újra.',
        'identity_locked' => 'Az eszközazonosságod zárolva van. Oldd fel az alkalmazást, és próbáld újra.',
        'identity_needs_lock' => 'Először állítsa be az alkalmazászárat — ez védi az eszköz identitását.',
        'safety_number_changed' => 'A másik eszköz megváltozott az összehasonlítás közben. Megerősítés előtt ellenőrizd újra az alábbi szavakat.',
    ],
];
