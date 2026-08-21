<?php

declare(strict_types=1);

return [
    'heading' => 'Postkastid',
    'intro' => 'Ühenda Gmaili ja Microsoft 365 postkastid, et Beatrax saaks neist kviitungeid otsida.',

    'connection_canceled' => 'Ühendamine tühistati.',
    'connection_failed' => 'Ühendust ei õnnestunud lõpule viia.',

    'backfilling' => 'Impordin tagantjärele',
    'messages_suffix' => 'kirja',

    'connect_heading' => 'Ühenda oma e-post',
    'connect_body' => 'Impordi kviitungid PayPalist, ICS Cardsist, Google Playst ja teistelt kaupmeestelt, andes Beatraxile ühele või mitmele postkastile ainult lugemisõiguse.',
    'connect_gmail' => 'Ühenda Gmail',
    'connect_microsoft' => 'Ühenda Microsoft 365',
    'readonly_note' => 'Beatrax ainult loeb kirju. See ei saada, sildista, teisalda ega kustuta sinu postkastis kunagi midagi.',

    'months' => ':count kuu|:count kuud',
    'not_scanned_yet' => 'veel skannimata',
    'last_scanned' => 'viimati skannitud',
    'window_prefix' => 'Aken:',
    'edit' => 'Muuda',

    'badge' => [
        'idle' => 'Ootel',
        'backfilling' => 'Impordin tagantjärele',
        'scanning' => 'Skannin',
        'rate_limited' => 'Päringupiirang',
        'needs_reauth' => 'Vajab uuesti autentimist',
        'error' => 'Viga',
    ],

    'retry_seconds' => 'proovin uuesti :ns pärast',
    'retry_minutes' => 'proovin uuesti :nm pärast',
    'retry_hours' => 'proovin uuesti :nh pärast',

    'reconnect' => 'Ühenda uuesti',
    'disconnect' => 'Ühenda lahti',
    'scan_now' => 'Skanni kohe',
    'scan_in_progress_title' => 'Skannimine juba käib',

    'add_another' => 'Lisa veel üks postkast',
    'gmail_card_body' => 'Ühenda Gmaili konto, et Beatrax saaks sealt kviitungeid otsida.',
    'microsoft_card_body' => 'Ühenda Microsoft 365 või Outlook.com konto, et Beatrax saaks sealt kviitungeid otsida.',

    'discovered_heading' => 'Leitud saatjad',
    'discovered_body' => 'Saatjad, kes näivad kviitungeid saatvat, kuid keda pole veel sinu teadaolevate kviitungisaatjate loendis. Lisa need, keda soovid Beatraxil skannida, ja peida ülejäänud.',
    'last_seen' => 'viimati nähtud',
    'seen_times' => 'Nähtud :count kord|Nähtud :count korda',
    'add' => 'Lisa',
    'add_aria' => 'Lisa :email',
    'dismiss' => 'Peida',
    'dismiss_aria' => 'Peida :email',

    'toast' => [
        'scan_in_progress' => 'Skannimine juba käib.',
        'scan_started' => 'Skannimine algas.',
        'sender_added' => 'Saatja lisatud.',
        'sender_dismissed' => 'Saatja peidetud.',
    ],
];
