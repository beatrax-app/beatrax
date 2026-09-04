<?php

declare(strict_types=1);

return [
    'heading' => 'Pastkastes',
    'intro' => 'Pievienojiet Gmail un Microsoft 365 pastkastes, lai Beatrax varētu tajās meklēt čekus.',
    'intro_phone' => 'Pastkastu skenēšana notiek datora lietotnē, nevis šajā tālrunī.',

    'phone_heading' => 'Šis tālrunis pastkastes neskenē',
    'phone_body' => 'Pievienojiet Gmail vai Microsoft 365 datora lietotnē — tur atrastie čeki nonāk šeit ar sinhronizāciju.',
    'connection_canceled' => 'Pievienošana atcelta.',
    'connection_failed' => 'Pievienošanu neizdevās pabeigt.',

    'backfilling' => 'Ielādē vēsturi',
    'backfill_progress' => ':fetched / ~:count ziņojumu|:fetched / ~:count ziņojums|:fetched / ~:count ziņojumi',

    'connect_heading' => 'Pievienojiet savu e-pastu',
    'connect_body' => 'Importējiet čekus no PayPal, ICS Cards, Google Play un citiem tirgotājiem, piešķirot Beatrax tikai lasīšanas piekļuvi vienai vai vairākām savām pastkastēm.',
    'connect_body_phone' => 'Čekus no PayPal, ICS Cards, Google Play un citiem tirgotājiem importē datora lietotne no tām pastkastēm, kurām piešķirat tai tikai lasīšanas piekļuvi. Šis tālrunis rāda, ko šis imports atrod.',
    'connect_gmail' => 'Pievienot Gmail',
    'connect_microsoft' => 'Pievienot Microsoft 365',
    'readonly_note' => 'Beatrax ziņojumus tikai lasa. Tā nekad neko nesūta, neatzīmē, nepārvieto un nedzēš jūsu pastkastē.',

    'months' => ':count mēnešu|:count mēnesis|:count mēneši',
    'not_scanned_yet' => 'vēl nav skenēts',
    'not_scanned_yet_phone' => 'šajā tālrunī nav skenēts',
    'last_scanned' => 'pēdējoreiz skenēts',
    'window_prefix' => 'Periods:',
    'edit' => 'Rediģēt',

    'badge' => [
        'idle' => 'Dīkstāvē',
        'backfilling' => 'Ielādē vēsturi',
        'scanning' => 'Skenē',
        'rate_limited' => 'Ierobežots pieprasījumu skaits',
        'needs_reauth' => 'Nepieciešama atkārtota autorizācija',
        'error' => 'Kļūda',
    ],

    'error_detail' => 'Pēdējā skenēšana netika pabeigta. Mēģiniet “Skenēt tagad” vai pievienojiet šo pastkasti atkārtoti.',
    'oauth_state_mismatch' => 'Šī savienojuma saite ir beigusies vai jau izmantota. Sāciet savienošanu no jauna.',
    'oauth_client_missing' => 'Vienreizējā iestatīšana šim pasta pakalpojumam šajā ierīcē nav pabeigta, tāpēc vēl nav ar ko izveidot savienojumu. Nospiediet Pievienot vēlreiz, lai to pabeigtu.',
    'oauth_no_code' => 'Jūsu pasta pakalpojums nosūtīja jūs atpakaļ bez koda, kas Beatrax nepieciešams pabeigšanai, tāpēc neviena pastkaste netika pievienota. Sāciet savienošanu no jauna.',
    'oauth_grant_refused' => 'Jūsu pasta pakalpojums noraidīja Beatrax piešķirto atļauju — tā ir beigusies vai atsaukta. Sāciet savienošanu no jauna un piešķiriet to.',
    'oauth_exchange_failed' => 'Jūsu pasta pakalpojums savienošanu nepabeidza, tāpēc neviena pastkaste netika pievienota. Mēģiniet vēlreiz pēc dažām minūtēm.',
    'oauth_not_saved' => 'Savienojumu neizdevās saglabāt šajā ierīcē, tāpēc neviena pastkaste netika pievienota. Mēģiniet vēlreiz — ja tas joprojām neizdodas, lietotnes žurnālā ir pierakstīts, kas to apturēja.',
    'oauth_no_offline_access_google' => 'Google nepiešķīra pastāvīgo atļauju, kas Beatrax nepieciešama, tāpēc šī pastkaste stundas laikā pārstātu tikt skenēta. Publicējiet savu OAuth piekrišanas ekrānu ražošanā un pēc tam savienojiet vēlreiz.',
    'oauth_no_offline_access' => 'Jūsu pasta pakalpojums nepiešķīra pastāvīgo atļauju, kas Beatrax nepieciešama, tāpēc šī pastkaste stundas laikā pārstātu tikt skenēta. Savienojiet vēlreiz un, kad tiek prasīts, atļaujiet bezsaistes piekļuvi.',
    'oauth_no_offline_access_google_phone' => 'Google nepiešķīra pastāvīgo atļauju, kas Beatrax nepieciešama, tāpēc netika pievienota neviena pastkaste. Publicējiet savu OAuth piekrišanas ekrānu ražošanā un pēc tam savienojiet vēlreiz — pati skenēšana notiek datora lietotnē.',
    'oauth_no_offline_access_phone' => 'Jūsu pasta pakalpojums nepiešķīra pastāvīgo atļauju, kas Beatrax nepieciešama, tāpēc netika pievienota neviena pastkaste. Savienojiet vēlreiz un, kad tiek prasīts, atļaujiet bezsaistes piekļuvi — pati skenēšana notiek datora lietotnē.',

    'retry_seconds' => 'mēģinās vēlreiz pēc :ns',
    'retry_minutes' => 'mēģinās vēlreiz pēc :nm',
    'retry_hours' => 'mēģinās vēlreiz pēc :nh',

    'reconnect' => 'Pievienot atkārtoti',
    'disconnect' => 'Atvienot',
    'scan_now' => 'Skenēt tagad',
    'scan_in_progress_title' => 'Skenēšana jau notiek',

    'add_another' => 'Pievienot vēl vienu pastkasti',
    'gmail_card_body' => 'Pievienojiet Gmail kontu, lai Beatrax tajā varētu meklēt čekus.',
    'microsoft_card_body' => 'Pievienojiet Microsoft 365 vai Outlook.com kontu, lai Beatrax tajā varētu meklēt čekus.',
    'gmail_card_body_phone' => 'Gmail skenē datora lietotne. Šeit pievienots konts nekad netiek skenēts pats no sevis.',
    'microsoft_card_body_phone' => 'Microsoft 365 un Outlook.com skenē datora lietotne. Šeit pievienots konts nekad netiek skenēts pats no sevis.',

    'discovered_heading' => 'Atklātie sūtītāji',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (izraksti)',
    ],
    'discovered_body' => 'Sūtītāji, kas izskatās pēc čeku sūtītājiem, bet vēl nav jūsu zināmo čeku sarakstā. Pievienojiet tos, kurus vēlaties skenēt ar Beatrax; pārējos aizveriet.',
    'last_seen' => 'pēdējoreiz redzēts',
    'seen_times' => 'Redzēts :count reižu|Redzēts :count reizi|Redzēts :count reizes',
    'add' => 'Pievienot',
    'add_aria' => 'Pievienot :email',
    'dismiss' => 'Aizvērt',
    'dismiss_aria' => 'Aizvērt :email',

    'toast' => [
        'reconnect_first' => 'Pirms skenēšanas atkārtoti savienojiet šo pastkasti.',
        'scan_in_progress' => 'Skenēšana jau notiek.',
        'scan_started' => 'Skenēšana sākta.',
        'sender_added' => 'Sūtītājs pievienots.',
        'sender_dismissed' => 'Sūtītājs aizvērts.',
    ],
];
