<?php

declare(strict_types=1);

return [
    'heading' => 'Postkastid',
    'intro' => 'Ühenda Gmaili ja Microsoft 365 postkastid, et Beatrax saaks neist kviitungeid otsida.',
    'intro_phone' => 'Postkastide skannimine toimub lauaarvuti rakenduses, mitte selles telefonis.',

    'phone_heading' => 'See telefon ei skanni postkaste',
    'phone_body' => 'Ühenda Gmail või Microsoft 365 lauaarvuti rakenduses — sealt leitud kviitungid jõuavad siia sünkroonimise kaudu.',
    'connection_canceled' => 'Ühendamine tühistati.',
    'connection_failed' => 'Ühendust ei õnnestunud lõpule viia.',

    'backfilling' => 'Impordin tagantjärele',
    'backfill_progress' => ':fetched / ~:count kiri|:fetched / ~:count kirja',

    'connect_heading' => 'Ühenda oma e-post',
    'connect_body' => 'Impordi kviitungid PayPalist, ICS Cardsist, Google Playst ja teistelt kaupmeestelt, andes Beatraxile ühele või mitmele postkastile ainult lugemisõiguse.',
    'connect_body_phone' => 'Kviitungid PayPalist, ICS Cardsist, Google Playst ja teistelt kaupmeestelt impordib lauaarvuti rakendus nendest postkastidest, millele annad sellele ainult lugemisõiguse. See telefon näitab, mida see import leiab.',
    'connect_gmail' => 'Ühenda Gmail',
    'connect_microsoft' => 'Ühenda Microsoft 365',
    'readonly_note' => 'Beatrax ainult loeb kirju. See ei saada, sildista, teisalda ega kustuta sinu postkastis kunagi midagi.',

    'months' => ':count kuu|:count kuud',
    'not_scanned_yet' => 'veel skannimata',
    'not_scanned_yet_phone' => 'selles telefonis skannimata',
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

    'error_detail' => 'Viimane skannimine ei lõppenud. Proovige „Skanni kohe“ või ühendage see postkast uuesti.',
    'oauth_state_mismatch' => 'See ühenduslink on aegunud või juba kasutatud. Alusta ühendamist uuesti.',
    'oauth_client_missing' => 'Selle e-posti teenuse ühekordne seadistus ei ole selles seadmes lõpetatud, seega pole veel millegagi ühendada. Vajuta uuesti Ühenda, et see lõpuni teha.',
    'oauth_no_code' => 'Sinu e-posti teenus saatis su tagasi ilma koodita, mida Beatrax lõpetamiseks vajab, seega ühtegi postkasti ei ühendatud. Alusta ühendamist uuesti.',
    'oauth_grant_refused' => 'Sinu e-posti teenus keeldus Beatraxile antud loast — see on aegunud või tagasi võetud. Alusta ühendamist uuesti ja anna luba.',
    'oauth_exchange_failed' => 'Sinu e-posti teenus ei viinud ühendamist lõpuni, seega postkasti ei lisatud. Proovi mõne minuti pärast uuesti.',
    'oauth_not_saved' => 'Ühendust ei õnnestunud sellesse seadmesse salvestada, seega postkasti ei lisatud. Proovi uuesti — kui see ikka ebaõnnestub, kirjutab rakenduse logi üles, mis selle peatas.',
    'oauth_no_offline_access_google' => 'Google ei andnud püsivat luba, mida Beatrax vajab, seega lõpetaks see postkast tunni jooksul skannimise. Avalda oma OAuthi nõusolekuekraan tootmisse ja ühenda siis uuesti.',
    'oauth_no_offline_access' => 'Sinu e-posti teenus ei andnud püsivat luba, mida Beatrax vajab, seega lõpetaks see postkast tunni jooksul skannimise. Ühenda uuesti ja luba küsimisel võrguühenduseta juurdepääs.',
    'oauth_no_offline_access_google_phone' => 'Google ei andnud püsivat luba, mida Beatrax vajab, seega ei ühendatud ühtegi postkasti. Avalda oma OAuthi nõusolekuekraan tootmisse ja ühenda siis uuesti — skannimine ise toimub lauaarvuti rakenduses.',
    'oauth_no_offline_access_phone' => 'Sinu e-posti teenus ei andnud püsivat luba, mida Beatrax vajab, seega ei ühendatud ühtegi postkasti. Ühenda uuesti ja luba küsimisel võrguühenduseta juurdepääs — skannimine ise toimub lauaarvuti rakenduses.',

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
    'gmail_card_body_phone' => 'Gmaili skannib lauaarvuti rakendus. Siia ühendatud kontot ei skannita kunagi iseenesest.',
    'microsoft_card_body_phone' => 'Microsoft 365 ja Outlook.com skannib lauaarvuti rakendus. Siia ühendatud kontot ei skannita kunagi iseenesest.',

    'discovered_heading' => 'Leitud saatjad',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (väljavõtted)',
    ],
    'discovered_body' => 'Saatjad, kes näivad kviitungeid saatvat, kuid keda pole veel sinu teadaolevate kviitungisaatjate loendis. Lisa need, keda soovid Beatraxil skannida, ja peida ülejäänud.',
    'last_seen' => 'viimati nähtud',
    'seen_times' => 'Nähtud :count kord|Nähtud :count korda',
    'add' => 'Lisa',
    'add_aria' => 'Lisa :email',
    'dismiss' => 'Peida',
    'dismiss_aria' => 'Peida :email',

    'toast' => [
        'reconnect_first' => 'Ühenda see postkast enne skannimist uuesti.',
        'scan_in_progress' => 'Skannimine juba käib.',
        'scan_started' => 'Skannimine algas.',
        'sender_added' => 'Saatja lisatud.',
        'sender_dismissed' => 'Saatja peidetud.',
    ],
];
