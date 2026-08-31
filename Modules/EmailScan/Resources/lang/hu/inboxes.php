<?php

declare(strict_types=1);

return [
    'heading' => 'Postafiókok',
    'intro' => 'Csatlakoztass Gmail- és Microsoft 365-postafiókokat, hogy a Beatrax bizonylatokat kereshessen bennük.',
    'intro_phone' => 'A postafiókok átvizsgálása az asztali alkalmazásban fut, nem ezen a telefonon.',

    'phone_heading' => 'Ez a telefon nem vizsgál postafiókokat',
    'phone_body' => 'Csatlakoztasd a Gmailt vagy a Microsoft 365-öt az asztali alkalmazásban — az ott talált bizonylatok szinkronizálással jutnak ide.',
    'connection_canceled' => 'A csatlakoztatás megszakítva.',
    'connection_failed' => 'A csatlakoztatást nem sikerült befejezni.',

    'backfilling' => 'Visszamenőleges import',
    'backfill_progress' => ':fetched / ~:count üzenet|:fetched / ~:count üzenet',

    'connect_heading' => 'Csatlakoztasd az e-mail-fiókod',
    'connect_body' => 'Importáld a PayPal, az ICS Cards, a Google Play és más kereskedők bizonylatait úgy, hogy csak olvasási hozzáférést adsz a Beatraxnak egy vagy több postafiókodhoz.',
    'connect_body_phone' => 'A PayPal, az ICS Cards, a Google Play és más kereskedők bizonylatait az asztali alkalmazás importálja azokból a postafiókokból, amelyekhez csak olvasási hozzáférést adsz neki. Ez a telefon azt mutatja, amit ez az import talál.',
    'connect_gmail' => 'Gmail csatlakoztatása',
    'connect_microsoft' => 'Microsoft 365 csatlakoztatása',
    'readonly_note' => 'A Beatrax csak olvassa az üzeneteket. Soha nem küld, nem címkéz, nem mozgat és nem töröl semmit a postafiókodban.',

    'months' => ':count hónap|:count hónap',
    'not_scanned_yet' => 'még nem vizsgáltuk',
    'not_scanned_yet_phone' => 'ezen a telefonon nem vizsgáltuk',
    'last_scanned' => 'utolsó vizsgálat',
    'window_prefix' => 'Időszak:',
    'edit' => 'Szerkesztés',

    'badge' => [
        'idle' => 'Tétlen',
        'backfilling' => 'Visszamenőleges import',
        'scanning' => 'Vizsgálat',
        'rate_limited' => 'Korlátozva',
        'needs_reauth' => 'Újbóli hitelesítés kell',
        'error' => 'Hiba',
    ],

    'error_detail' => 'A legutóbbi vizsgálat nem fejeződött be. Próbálja a Vizsgálat most lehetőséget, vagy csatlakozzon újra ehhez a postafiókhoz.',
    'oauth_no_code' => 'A levelezőszolgáltatód anélkül a kód nélkül küldött vissza, amelyre a Beatraxnak a befejezéshez szüksége van, így egyetlen postafiók sem lett csatlakoztatva. Kezdd elölről a csatlakoztatást.',
    'oauth_grant_refused' => 'A levelezőszolgáltatód elutasította a Beatraxnak adott engedélyt — lejárt vagy visszavonták. Kezdd elölről a csatlakoztatást, és add meg az engedélyt.',
    'oauth_exchange_failed' => 'A levelezőszolgáltatód nem fejezte be a csatlakoztatást, így egyetlen postafiók sem lett hozzáadva. Próbáld újra néhány perc múlva.',
    'oauth_not_saved' => 'A kapcsolatot nem sikerült elmenteni ezen az eszközön, így egyetlen postafiók sem lett hozzáadva. Próbáld újra — ha továbbra is hibázik, az alkalmazás naplója rögzíti, mi állította meg.',
    'oauth_no_offline_access_google' => 'A Google nem adta meg azt a tartós engedélyt, amelyre a Beatraxnak szüksége van, így ez a postafiók egy órán belül abbahagyná a beolvasást. Tedd közzé az OAuth-hozzájárulási képernyődet éles környezetbe, majd csatlakoztasd újra.',
    'oauth_no_offline_access' => 'A levelezőszolgáltatód nem adta meg azt a tartós engedélyt, amelyre a Beatraxnak szüksége van, így ez a postafiók egy órán belül abbahagyná a beolvasást. Csatlakoztasd újra, és engedélyezd az offline hozzáférést, amikor kéri.',
    'oauth_no_offline_access_google_phone' => 'A Google nem adta meg azt a tartós engedélyt, amelyre a Beatraxnak szüksége van, így egyetlen postafiók sem csatlakozott. Tedd közzé az OAuth-hozzájárulási képernyődet éles környezetbe, majd csatlakoztasd újra — maga a beolvasás az asztali alkalmazásban fut.',
    'oauth_no_offline_access_phone' => 'A levelezőszolgáltatód nem adta meg azt a tartós engedélyt, amelyre a Beatraxnak szüksége van, így egyetlen postafiók sem csatlakozott. Csatlakoztasd újra, és engedélyezd az offline hozzáférést, amikor kéri — maga a beolvasás az asztali alkalmazásban fut.',

    'retry_seconds' => 'újrapróbálkozás :nmp múlva',
    'retry_minutes' => 'újrapróbálkozás :np múlva',
    'retry_hours' => 'újrapróbálkozás :nó múlva',

    'reconnect' => 'Újracsatlakozás',
    'disconnect' => 'Leválasztás',
    'scan_now' => 'Vizsgálat most',
    'scan_in_progress_title' => 'Már fut egy vizsgálat',

    'add_another' => 'További postafiók hozzáadása',
    'gmail_card_body' => 'Csatlakoztass egy Gmail-fiókot, hogy a Beatrax bizonylatokat kereshessen benne.',
    'microsoft_card_body' => 'Csatlakoztass egy Microsoft 365- vagy Outlook.com-fiókot, hogy a Beatrax bizonylatokat kereshessen benne.',
    'gmail_card_body_phone' => 'A Gmailt az asztali alkalmazás vizsgálja. Az itt csatlakoztatott fiókot semmi sem vizsgálja magától.',
    'microsoft_card_body_phone' => 'A Microsoft 365-öt és az Outlook.comot az asztali alkalmazás vizsgálja. Az itt csatlakoztatott fiókot semmi sem vizsgálja magától.',

    'discovered_heading' => 'Felfedezett feladók',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (kivonatok)',
    ],
    'discovered_body' => 'Olyan feladók, amelyek bizonylatot küldhetnek, de még nincsenek az ismert bizonylatküldők listáján. Add hozzá azokat, amelyeket a Beatrax vizsgáljon; a többit vesd el.',
    'last_seen' => 'legutóbb',
    'seen_times' => ':count alkalommal fordult elő|:count alkalommal fordult elő',
    'add' => 'Hozzáadás',
    'add_aria' => 'A(z) :email hozzáadása',
    'dismiss' => 'Elvetés',
    'dismiss_aria' => 'A(z) :email elvetése',

    'toast' => [
        'reconnect_first' => 'Csatlakoztasd újra ezt a postafiókot a beolvasás előtt.',
        'scan_in_progress' => 'Már fut egy vizsgálat.',
        'scan_started' => 'A vizsgálat elindult.',
        'sender_added' => 'Feladó hozzáadva.',
        'sender_dismissed' => 'Feladó elvetve.',
    ],
];
