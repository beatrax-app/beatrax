<?php

declare(strict_types=1);

return [
    'heading' => 'Postafiókok',
    'intro' => 'Csatlakoztass Gmail- és Microsoft 365-postafiókokat, hogy a Beatrax bizonylatokat kereshessen bennük.',

    'connection_canceled' => 'A csatlakoztatás megszakítva.',
    'connection_failed' => 'A csatlakoztatást nem sikerült befejezni.',

    'backfilling' => 'Visszamenőleges import',
    'messages_suffix' => 'üzenet',

    'connect_heading' => 'Csatlakoztasd az e-mail-fiókod',
    'connect_body' => 'Importáld a PayPal, az ICS Cards, a Google Play és más kereskedők bizonylatait úgy, hogy csak olvasási hozzáférést adsz a Beatraxnak egy vagy több postafiókodhoz.',
    'connect_gmail' => 'Gmail csatlakoztatása',
    'connect_microsoft' => 'Microsoft 365 csatlakoztatása',
    'readonly_note' => 'A Beatrax csak olvassa az üzeneteket. Soha nem küld, nem címkéz, nem mozgat és nem töröl semmit a postafiókodban.',

    'month' => '1 hónap',
    'months' => ':count hónap',
    'not_scanned_yet' => 'még nem vizsgáltuk',
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

    'retry_seconds' => 'újrapróbálkozás :nmp múlva',
    'retry_minutes' => 'újrapróbálkozás :np múlva',
    'retry_hours' => 'újrapróbálkozás :nó múlva',

    'reconnect' => 'Újracsatlakozás',
    'scan_now' => 'Vizsgálat most',
    'scan_in_progress_title' => 'Már fut egy vizsgálat',

    'add_another' => 'További postafiók hozzáadása',
    'gmail_card_body' => 'Csatlakoztass egy Gmail-fiókot, hogy a Beatrax bizonylatokat kereshessen benne.',
    'microsoft_card_body' => 'Csatlakoztass egy Microsoft 365- vagy Outlook.com-fiókot, hogy a Beatrax bizonylatokat kereshessen benne.',

    'discovered_heading' => 'Felfedezett feladók',
    'discovered_body' => 'Olyan feladók, amelyek bizonylatot küldhetnek, de még nincsenek az ismert bizonylatküldők listáján. Add hozzá azokat, amelyeket a Beatrax vizsgáljon; a többit vesd el.',
    'last_seen' => 'legutóbb',
    'seen_times' => ':count alkalommal fordult elő',
    'add' => 'Hozzáadás',
    'add_aria' => 'A(z) :email hozzáadása',
    'dismiss' => 'Elvetés',
    'dismiss_aria' => 'A(z) :email elvetése',

    'toast' => [
        'scan_in_progress' => 'Már fut egy vizsgálat.',
        'scan_started' => 'A vizsgálat elindult.',
        'sender_added' => 'Feladó hozzáadva.',
        'sender_dismissed' => 'Feladó elvetve.',
    ],
];
