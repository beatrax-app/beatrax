<?php

declare(strict_types=1);

return [
    'heading' => 'Căsuțe poștale',
    'intro' => 'Conectează căsuțe Gmail și Microsoft 365 ca Beatrax să le poată scana după bonuri.',

    'connection_canceled' => 'Conectare anulată.',
    'connection_failed' => 'Conectarea nu a putut fi finalizată.',

    'backfilling' => 'Import retroactiv',
    'messages_suffix' => 'mesaje',

    'connect_heading' => 'Conectează-ți e-mailul',
    'connect_body' => 'Importă bonuri de la PayPal, ICS Cards, Google Play și alți comercianți, dând Beatrax acces doar pentru citire la una sau mai multe căsuțe poștale.',
    'connect_gmail' => 'Conectează Gmail',
    'connect_microsoft' => 'Conectează Microsoft 365',
    'readonly_note' => 'Beatrax doar citește mesajele. Nu trimite, nu etichetează, nu mută și nu șterge niciodată nimic din căsuța ta.',

    'month' => '1 lună',
    'months' => ':count luni',
    'not_scanned_yet' => 'încă nescanat',
    'last_scanned' => 'ultima scanare',
    'window_prefix' => 'Interval:',
    'edit' => 'Editează',

    'badge' => [
        'idle' => 'Inactiv',
        'backfilling' => 'Import retroactiv',
        'scanning' => 'Se scanează',
        'rate_limited' => 'Limitat de rată',
        'needs_reauth' => 'Necesită reautentificare',
        'error' => 'Eroare',
    ],

    'retry_seconds' => 'reîncercare în :ns',
    'retry_minutes' => 'reîncercare în :nm',
    'retry_hours' => 'reîncercare în :nh',

    'reconnect' => 'Reconectează',
    'scan_now' => 'Scanează acum',
    'scan_in_progress_title' => 'O scanare este deja în curs',

    'add_another' => 'Adaugă altă căsuță poștală',
    'gmail_card_body' => 'Conectează un cont Gmail ca Beatrax să îl poată scana după bonuri.',
    'microsoft_card_body' => 'Conectează un cont Microsoft 365 sau Outlook.com ca Beatrax să îl poată scana după bonuri.',

    'discovered_heading' => 'Expeditori descoperiți',
    'discovered_body' => 'Expeditori care par să trimită bonuri, dar nu sunt încă pe lista ta de expeditori cunoscuți. Adaugă-i pe cei pe care vrei ca Beatrax să îi scaneze; pe ceilalți închide-i.',
    'last_seen' => 'văzut ultima dată',
    'seen_times' => 'Văzut de :count ori',
    'add' => 'Adaugă',
    'add_aria' => 'Adaugă :email',
    'dismiss' => 'Închide',
    'dismiss_aria' => 'Închide :email',

    'toast' => [
        'scan_in_progress' => 'O scanare este deja în curs.',
        'scan_started' => 'Scanare pornită.',
        'sender_added' => 'Expeditor adăugat.',
        'sender_dismissed' => 'Expeditor închis.',
    ],
];
