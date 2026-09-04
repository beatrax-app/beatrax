<?php

declare(strict_types=1);

return [
    'heading' => 'Indbakker',
    'intro' => 'Forbind indbakker fra Gmail og Microsoft 365, så Beatrax kan scanne dem for kvitteringer.',
    'intro_phone' => 'Scanning af indbakker sker i computerappen, ikke på denne telefon.',

    'phone_heading' => 'Denne telefon scanner ikke postkasser',
    'phone_body' => 'Forbind Gmail eller Microsoft 365 i computerappen — kvitteringerne, den finder, kommer hertil via synkronisering.',
    'connection_canceled' => 'Forbindelsen blev annulleret.',
    'connection_failed' => 'Forbindelsen kunne ikke gennemføres.',

    'backfilling' => 'Henter historik',
    'backfill_progress' => ':fetched / ~:count besked|:fetched / ~:count beskeder',

    'connect_heading' => 'Forbind din e-mail',
    'connect_body' => 'Importér kvitteringer fra PayPal, ICS Cards, Google Play og andre forhandlere ved at give Beatrax skrivebeskyttet adgang til en eller flere af dine indbakker.',
    'connect_body_phone' => 'Kvitteringer fra PayPal, ICS Cards, Google Play og andre forhandlere importeres af computerappen fra de indbakker, du giver den skrivebeskyttet adgang til. Denne telefon viser, hvad den import finder.',
    'connect_gmail' => 'Forbind Gmail',
    'connect_microsoft' => 'Forbind Microsoft 365',
    'readonly_note' => 'Beatrax læser kun beskeder. Det sender, mærker, flytter eller sletter aldrig noget i din indbakke.',

    'months' => ':count måned|:count måneder',
    'not_scanned_yet' => 'ikke scannet endnu',
    'not_scanned_yet_phone' => 'ikke scannet på denne telefon',
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

    'error_detail' => 'Den seneste scanning blev ikke fuldført. Prøv Scan nu, eller forbind denne indbakke igen.',
    'oauth_state_mismatch' => 'Dette forbindelseslink er udløbet eller allerede brugt. Start forbindelsen forfra.',
    'oauth_client_missing' => 'Engangsopsætningen for den mailudbyder er ikke færdig på denne enhed, så der er endnu ikke noget at forbinde med. Tryk på Forbind igen for at gøre den færdig.',
    'oauth_no_code' => 'Din mailudbyder sendte dig tilbage uden den kode, Beatrax skal bruge for at afslutte, så ingen postkasse blev forbundet. Start forbindelsen forfra.',
    'oauth_grant_refused' => 'Din mailudbyder afviste den tilladelse, Beatrax havde fået — den er udløbet eller trukket tilbage. Start forbindelsen forfra, og godkend den.',
    'oauth_exchange_failed' => 'Din mailudbyder fuldførte ikke forbindelsen, så ingen postkasse blev tilføjet. Prøv igen om et par minutter.',
    'oauth_not_saved' => 'Forbindelsen kunne ikke gemmes på denne enhed, så ingen postkasse blev tilføjet. Prøv igen — bliver den ved med at fejle, noterer appens log, hvad der stoppede den.',
    'oauth_no_offline_access_google' => 'Google gav ikke den varige tilladelse, Beatrax skal bruge, så denne postkasse ville holde op med at blive scannet inden for en time. Udgiv dit OAuth-samtykkeskærmbillede til produktion, og forbind så igen.',
    'oauth_no_offline_access' => 'Din mailudbyder gav ikke den varige tilladelse, Beatrax skal bruge, så denne postkasse ville holde op med at blive scannet inden for en time. Forbind igen, og tillad offlineadgang, når du bliver spurgt.',
    'oauth_no_offline_access_google_phone' => 'Google gav ikke den varige tilladelse, Beatrax skal bruge, så der blev ikke forbundet nogen postkasse. Udgiv dit OAuth-samtykkeskærmbillede til produktion, og forbind så igen — selve scanningen sker i computerappen.',
    'oauth_no_offline_access_phone' => 'Din mailudbyder gav ikke den varige tilladelse, Beatrax skal bruge, så der blev ikke forbundet nogen postkasse. Forbind igen, og tillad offlineadgang, når du bliver spurgt — selve scanningen sker i computerappen.',

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
    'gmail_card_body_phone' => 'Gmail scannes af computerappen. En konto, du forbinder her, bliver aldrig scannet af sig selv.',
    'microsoft_card_body_phone' => 'Microsoft 365 og Outlook.com scannes af computerappen. En konto, du forbinder her, bliver aldrig scannet af sig selv.',

    'discovered_heading' => 'Fundne afsendere',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (kontoudtog)',
    ],
    'discovered_body' => 'Afsendere, der ser ud til at sende kvitteringer, men som endnu ikke står på din liste over kendte kvitteringsafsendere. Tilføj dem, du vil have Beatrax til at scanne, og luk resten.',
    'last_seen' => 'sidst set',
    'seen_times' => 'Set :count gang|Set :count gange',
    'add' => 'Tilføj',
    'add_aria' => 'Tilføj :email',
    'dismiss' => 'Luk',
    'dismiss_aria' => 'Luk :email',

    'toast' => [
        'reconnect_first' => 'Tilslut denne indbakke igen, før du scanner.',
        'scan_in_progress' => 'Scanning er allerede i gang.',
        'scan_started' => 'Scanningen er startet.',
        'sender_added' => 'Afsenderen er tilføjet.',
        'sender_dismissed' => 'Afsenderen er lukket.',
    ],
];
