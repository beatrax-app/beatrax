<?php

declare(strict_types=1);

return [
    'heading' => 'Indbakker',
    'intro' => 'Forbind indbakker fra Gmail og Microsoft 365, så Beatrax kan scanne dem for kvitteringer.',

    'connection_canceled' => 'Forbindelsen blev annulleret.',
    'connection_failed' => 'Forbindelsen kunne ikke gennemføres.',

    'backfilling' => 'Henter historik',
    'messages_suffix' => 'beskeder',

    'connect_heading' => 'Forbind din e-mail',
    'connect_body' => 'Importér kvitteringer fra PayPal, ICS Cards, Google Play og andre forhandlere ved at give Beatrax skrivebeskyttet adgang til en eller flere af dine indbakker.',
    'connect_gmail' => 'Forbind Gmail',
    'connect_microsoft' => 'Forbind Microsoft 365',
    'readonly_note' => 'Beatrax læser kun beskeder. Det sender, mærker, flytter eller sletter aldrig noget i din indbakke.',

    'months' => ':count måned|:count måneder',
    'not_scanned_yet' => 'ikke scannet endnu',
    'last_scanned' => 'sidst scannet',
    'window_prefix' => 'Periode:',
    'edit' => 'Redigér',

    'badge' => [
        'idle' => 'Inaktiv',
        'backfilling' => 'Henter historik',
        'scanning' => 'Scanner',
        'rate_limited' => 'Hastighedsbegrænset',
        'needs_reauth' => 'Kræver ny godkendelse',
        'error' => 'Fejl',
    ],

    'retry_seconds' => 'nyt forsøg om :ns',
    'retry_minutes' => 'nyt forsøg om :nmin',
    'retry_hours' => 'nyt forsøg om :nt',

    'reconnect' => 'Forbind igen',
    'disconnect' => 'Afbryd',
    'scan_now' => 'Scan nu',
    'scan_in_progress_title' => 'Scanning er allerede i gang',

    'add_another' => 'Tilføj endnu en indbakke',
    'gmail_card_body' => 'Forbind en Gmail-konto, så Beatrax kan scanne den for kvitteringer.',
    'microsoft_card_body' => 'Forbind en Microsoft 365- eller Outlook.com-konto, så Beatrax kan scanne den for kvitteringer.',

    'discovered_heading' => 'Fundne afsendere',
    'discovered_body' => 'Afsendere, der ser ud til at sende kvitteringer, men som endnu ikke står på din liste over kendte kvitteringsafsendere. Tilføj dem, du vil have Beatrax til at scanne, og luk resten.',
    'last_seen' => 'sidst set',
    'seen_times' => 'Set :count gang|Set :count gange',
    'add' => 'Tilføj',
    'add_aria' => 'Tilføj :email',
    'dismiss' => 'Luk',
    'dismiss_aria' => 'Luk :email',

    'toast' => [
        'scan_in_progress' => 'Scanning er allerede i gang.',
        'scan_started' => 'Scanningen er startet.',
        'sender_added' => 'Afsenderen er tilføjet.',
        'sender_dismissed' => 'Afsenderen er lukket.',
    ],
];
