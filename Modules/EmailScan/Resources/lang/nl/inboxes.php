<?php

declare(strict_types=1);

return [
    'heading' => 'Postvakken',
    'intro' => 'Koppel Gmail- en Microsoft 365-postvakken zodat Beatrax ze op bonnen kan scannen.',

    'connection_canceled' => 'Verbinding geannuleerd.',
    'connection_failed' => 'Kon de verbinding niet voltooien.',

    'backfilling' => 'Bezig met ophalen',
    'messages_suffix' => 'berichten',

    'connect_heading' => 'Koppel je e-mail',
    'connect_body' => 'Importeer bonnen van PayPal, ICS Cards, Google Play en andere winkeliers door Beatrax alleen-lezen toegang te geven tot een of meer van je postvakken.',
    'connect_gmail' => 'Gmail koppelen',
    'connect_microsoft' => 'Microsoft 365 koppelen',
    'readonly_note' => 'Beatrax leest alleen berichten. Het verstuurt, labelt, verplaatst of verwijdert nooit iets in je postvak.',

    'months' => ':count maand|:count maanden',
    'not_scanned_yet' => 'nog niet gescand',
    'last_scanned' => 'laatst gescand',
    'window_prefix' => 'Periode:',
    'edit' => 'Bewerken',

    'badge' => [
        'idle' => 'Inactief',
        'backfilling' => 'Ophalen',
        'scanning' => 'Scannen',
        'rate_limited' => 'Snelheid beperkt',
        'needs_reauth' => 'Opnieuw verifiëren',
        'error' => 'Fout',
    ],

    'retry_seconds' => 'opnieuw over :ns',
    'retry_minutes' => 'opnieuw over :nm',
    'retry_hours' => 'opnieuw over :nu',

    'reconnect' => 'Opnieuw verbinden',
    'disconnect' => 'Ontkoppelen',
    'scan_now' => 'Nu scannen',
    'scan_in_progress_title' => 'Er loopt al een scan',

    'add_another' => 'Nog een postvak toevoegen',
    'gmail_card_body' => 'Koppel een Gmail-account zodat Beatrax het op bonnen kan scannen.',
    'microsoft_card_body' => 'Koppel een Microsoft 365- of Outlook.com-account zodat Beatrax het op bonnen kan scannen.',

    'discovered_heading' => 'Ontdekte afzenders',
    'discovered_body' => 'Afzenders die eruitzien alsof ze bonnen sturen maar nog niet op je lijst met bekende bonnen staan. Voeg de afzenders toe die je door Beatrax wilt laten scannen; wijs de rest af.',
    'last_seen' => 'laatst gezien',
    'seen_times' => ':count keer gezien|:count keer gezien',
    'add' => 'Toevoegen',
    'add_aria' => ':email toevoegen',
    'dismiss' => 'Afwijzen',
    'dismiss_aria' => ':email afwijzen',

    'toast' => [
        'scan_in_progress' => 'Er loopt al een scan.',
        'scan_started' => 'Scan gestart.',
        'sender_added' => 'Afzender toegevoegd.',
        'sender_dismissed' => 'Afzender afgewezen.',
    ],
];
