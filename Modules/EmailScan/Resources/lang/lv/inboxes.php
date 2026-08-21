<?php

declare(strict_types=1);

return [
    'heading' => 'Pastkastes',
    'intro' => 'Pievienojiet Gmail un Microsoft 365 pastkastes, lai Beatrax varētu tajās meklēt čekus.',

    'connection_canceled' => 'Pievienošana atcelta.',
    'connection_failed' => 'Pievienošanu neizdevās pabeigt.',

    'backfilling' => 'Ielādē vēsturi',
    'messages_suffix' => 'ziņojumi',

    'connect_heading' => 'Pievienojiet savu e-pastu',
    'connect_body' => 'Importējiet čekus no PayPal, ICS Cards, Google Play un citiem tirgotājiem, piešķirot Beatrax tikai lasīšanas piekļuvi vienai vai vairākām savām pastkastēm.',
    'connect_gmail' => 'Pievienot Gmail',
    'connect_microsoft' => 'Pievienot Microsoft 365',
    'readonly_note' => 'Beatrax ziņojumus tikai lasa. Tā nekad neko nesūta, neatzīmē, nepārvieto un nedzēš jūsu pastkastē.',

    'months' => ':count mēnešu|:count mēnesis|:count mēneši',
    'not_scanned_yet' => 'vēl nav skenēts',
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

    'discovered_heading' => 'Atklātie sūtītāji',
    'discovered_body' => 'Sūtītāji, kas izskatās pēc čeku sūtītājiem, bet vēl nav jūsu zināmo čeku sarakstā. Pievienojiet tos, kurus vēlaties skenēt ar Beatrax; pārējos aizveriet.',
    'last_seen' => 'pēdējoreiz redzēts',
    'seen_times' => 'Redzēts :count reižu|Redzēts :count reizi|Redzēts :count reizes',
    'add' => 'Pievienot',
    'add_aria' => 'Pievienot :email',
    'dismiss' => 'Aizvērt',
    'dismiss_aria' => 'Aizvērt :email',

    'toast' => [
        'scan_in_progress' => 'Skenēšana jau notiek.',
        'scan_started' => 'Skenēšana sākta.',
        'sender_added' => 'Sūtītājs pievienots.',
        'sender_dismissed' => 'Sūtītājs aizvērts.',
    ],
];
