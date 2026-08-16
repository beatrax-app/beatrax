<?php

declare(strict_types=1);

return [
    'heading' => 'Innbokser',
    'intro' => 'Koble til innbokser fra Gmail og Microsoft 365 slik at Beatrax kan skanne dem for kvitteringer.',

    'connection_canceled' => 'Tilkoblingen ble avbrutt.',
    'connection_failed' => 'Tilkoblingen kunne ikke fullføres.',

    'backfilling' => 'Henter historikk',
    'messages_suffix' => 'meldinger',

    'connect_heading' => 'Koble til e-posten din',
    'connect_body' => 'Importer kvitteringer fra PayPal, ICS Cards, Google Play og andre forhandlere ved å gi Beatrax skrivebeskyttet tilgang til en eller flere av innboksene dine.',
    'connect_gmail' => 'Koble til Gmail',
    'connect_microsoft' => 'Koble til Microsoft 365',
    'readonly_note' => 'Beatrax leser bare meldinger. Det sender, merker, flytter eller sletter aldri noe i innboksen din.',

    'month' => '1 måned',
    'months' => ':count måneder',
    'not_scanned_yet' => 'ikke skannet ennå',
    'last_scanned' => 'sist skannet',
    'window_prefix' => 'Periode:',
    'edit' => 'Rediger',

    'badge' => [
        'idle' => 'Inaktiv',
        'backfilling' => 'Henter historikk',
        'scanning' => 'Skanner',
        'rate_limited' => 'Hastighetsbegrenset',
        'needs_reauth' => 'Krever ny godkjenning',
        'error' => 'Feil',
    ],

    'retry_seconds' => 'nytt forsøk om :ns',
    'retry_minutes' => 'nytt forsøk om :nmin',
    'retry_hours' => 'nytt forsøk om :nt',

    'reconnect' => 'Koble til på nytt',
    'scan_now' => 'Skann nå',
    'scan_in_progress_title' => 'Skanning pågår allerede',

    'add_another' => 'Legg til en innboks til',
    'gmail_card_body' => 'Koble til en Gmail-konto slik at Beatrax kan skanne den for kvitteringer.',
    'microsoft_card_body' => 'Koble til en Microsoft 365- eller Outlook.com-konto slik at Beatrax kan skanne den for kvitteringer.',

    'discovered_heading' => 'Oppdagede avsendere',
    'discovered_body' => 'Avsendere som ser ut til å sende kvitteringer, men som ennå ikke står på listen din over kjente kvitteringsavsendere. Legg til dem du vil at Beatrax skal skanne, og lukk resten.',
    'last_seen' => 'sist sett',
    'seen_times' => 'Sett :count ganger',
    'add' => 'Legg til',
    'add_aria' => 'Legg til :email',
    'dismiss' => 'Lukk',
    'dismiss_aria' => 'Lukk :email',

    'toast' => [
        'scan_in_progress' => 'Skanning pågår allerede.',
        'scan_started' => 'Skanningen er startet.',
        'sender_added' => 'Avsenderen er lagt til.',
        'sender_dismissed' => 'Avsenderen er lukket.',
    ],
];
