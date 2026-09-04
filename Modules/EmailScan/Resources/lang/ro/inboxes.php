<?php

declare(strict_types=1);

return [
    'heading' => 'Căsuțe poștale',
    'intro' => 'Conectează căsuțe Gmail și Microsoft 365 ca Beatrax să le poată scana după bonuri.',
    'intro_phone' => 'Scanarea căsuțelor rulează în aplicația pentru computer, nu pe acest telefon.',

    'phone_heading' => 'Acest telefon nu scanează căsuțe poștale',
    'phone_body' => 'Conectează Gmail sau Microsoft 365 în aplicația pentru computer — bonurile găsite acolo ajung aici prin sincronizare.',
    'connection_canceled' => 'Conectare anulată.',
    'connection_failed' => 'Conectarea nu a putut fi finalizată.',

    'backfilling' => 'Import retroactiv',
    'backfill_progress' => ':fetched / ~:count mesaj|:fetched / ~:count mesaje|:fetched / ~:count de mesaje',

    'connect_heading' => 'Conectează-ți e-mailul',
    'connect_body' => 'Importă bonuri de la PayPal, ICS Cards, Google Play și alți comercianți, dând Beatrax acces doar pentru citire la una sau mai multe căsuțe poștale.',
    'connect_body_phone' => 'Bonurile de la PayPal, ICS Cards, Google Play și alți comercianți sunt importate de aplicația pentru computer, din căsuțele cărora le dai acces doar pentru citire. Acest telefon arată ce găsește acel import.',
    'connect_gmail' => 'Conectează Gmail',
    'connect_microsoft' => 'Conectează Microsoft 365',
    'readonly_note' => 'Beatrax doar citește mesajele. Nu trimite, nu etichetează, nu mută și nu șterge niciodată nimic din căsuța ta.',

    'months' => ':count lună|:count luni|:count de luni',
    'not_scanned_yet' => 'încă nescanat',
    'not_scanned_yet_phone' => 'nescanat pe acest telefon',
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

    'error_detail' => 'Ultima scanare nu s-a finalizat. Încercați Scanează acum sau reconectează această căsuță.',
    'oauth_state_mismatch' => 'Acest link de conectare a expirat sau a fost deja folosit. Începe conectarea de la capăt.',
    'oauth_client_missing' => 'Configurarea unică pentru acel furnizor de e-mail nu este terminată pe acest dispozitiv, așa că încă nu există nimic cu care să se facă conectarea. Apasă din nou Conectează ca să o termini.',
    'oauth_no_code' => 'Furnizorul tău de e-mail te-a trimis înapoi fără codul de care Beatrax are nevoie ca să încheie, așa că nu a fost conectată nicio cutie poștală. Începe conectarea de la capăt.',
    'oauth_grant_refused' => 'Furnizorul tău de e-mail a refuzat permisiunea dată lui Beatrax — a expirat sau a fost retrasă. Începe conectarea de la capăt și acord-o.',
    'oauth_exchange_failed' => 'Furnizorul tău de e-mail nu a încheiat conectarea, așa că nu a fost adăugată nicio cutie poștală. Încearcă din nou peste câteva minute.',
    'oauth_not_saved' => 'Conexiunea nu a putut fi salvată pe acest dispozitiv, așa că nu a fost adăugată nicio cutie poștală. Încearcă din nou — dacă tot eșuează, jurnalul aplicației notează ce a oprit-o.',
    'oauth_no_offline_access_google' => 'Google nu a acordat permisiunea de durată de care Beatrax are nevoie, așa că această cutie poștală ar înceta să fie scanată într-o oră. Publică ecranul tău de consimțământ OAuth în producție, apoi conectează din nou.',
    'oauth_no_offline_access' => 'Furnizorul tău de e-mail nu a acordat permisiunea de durată de care Beatrax are nevoie, așa că această cutie poștală ar înceta să fie scanată într-o oră. Conectează din nou și permite accesul offline când ți se cere.',
    'oauth_no_offline_access_google_phone' => 'Google nu a acordat permisiunea de durată de care Beatrax are nevoie, așa că nu a fost conectată nicio căsuță. Publică ecranul tău de consimțământ OAuth în producție, apoi conectează din nou — scanarea în sine rulează în aplicația pentru computer.',
    'oauth_no_offline_access_phone' => 'Furnizorul tău de e-mail nu a acordat permisiunea de durată de care Beatrax are nevoie, așa că nu a fost conectată nicio căsuță. Conectează din nou și permite accesul offline când ți se cere — scanarea în sine rulează în aplicația pentru computer.',

    'retry_seconds' => 'reîncercare în :ns',
    'retry_minutes' => 'reîncercare în :nm',
    'retry_hours' => 'reîncercare în :nh',

    'reconnect' => 'Reconectează',
    'disconnect' => 'Deconectează',
    'scan_now' => 'Scanează acum',
    'scan_in_progress_title' => 'O scanare este deja în curs',

    'add_another' => 'Adaugă altă căsuță poștală',
    'gmail_card_body' => 'Conectează un cont Gmail ca Beatrax să îl poată scana după bonuri.',
    'microsoft_card_body' => 'Conectează un cont Microsoft 365 sau Outlook.com ca Beatrax să îl poată scana după bonuri.',
    'gmail_card_body_phone' => 'Gmail este scanat de aplicația pentru computer. Un cont conectat aici nu este scanat niciodată de la sine.',
    'microsoft_card_body_phone' => 'Microsoft 365 și Outlook.com sunt scanate de aplicația pentru computer. Un cont conectat aici nu este scanat niciodată de la sine.',

    'discovered_heading' => 'Expeditori descoperiți',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (extrase)',
    ],
    'discovered_body' => 'Expeditori care par să trimită bonuri, dar nu sunt încă pe lista ta de expeditori cunoscuți. Adaugă-i pe cei pe care vrei ca Beatrax să îi scaneze; pe ceilalți închide-i.',
    'last_seen' => 'văzut ultima dată',
    'seen_times' => 'Văzut o dată|Văzut de :count ori|Văzut de :count de ori',
    'add' => 'Adaugă',
    'add_aria' => 'Adaugă :email',
    'dismiss' => 'Închide',
    'dismiss_aria' => 'Închide :email',

    'toast' => [
        'reconnect_first' => 'Reconectează această căsuță înainte de scanare.',
        'scan_in_progress' => 'O scanare este deja în curs.',
        'scan_started' => 'Scanare pornită.',
        'sender_added' => 'Expeditor adăugat.',
        'sender_dismissed' => 'Expeditor închis.',
    ],
];
