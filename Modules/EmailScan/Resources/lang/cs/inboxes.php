<?php

declare(strict_types=1);

return [
    'heading' => 'Schránky',
    'intro' => 'Připoj schránky Gmail a Microsoft 365, ať je Beatrax může skenovat kvůli účtenkám.',

    'connection_canceled' => 'Připojení zrušeno.',
    'connection_failed' => 'Připojení se nepodařilo dokončit.',

    'backfilling' => 'Doplňování',
    'messages_suffix' => 'zpráv',

    'connect_heading' => 'Připoj svůj e-mail',
    'connect_body' => 'Importuj účtenky z PayPalu, ICS Cards, Google Play i od dalších obchodníků tím, že Beatraxu dáš přístup jen pro čtení k jedné nebo více svým schránkám.',
    'connect_gmail' => 'Připojit Gmail',
    'connect_microsoft' => 'Připojit Microsoft 365',
    'readonly_note' => 'Beatrax zprávy pouze čte. Ve schránce nikdy nic neodesílá, neoznačuje, nepřesouvá ani nemaže.',

    'months' => ':count měs.|:count měs.|:count měs.',
    'not_scanned_yet' => 'zatím neskenováno',
    'last_scanned' => 'naposledy skenováno',
    'window_prefix' => 'Okno:',
    'edit' => 'Upravit',

    'badge' => [
        'idle' => 'Nečinné',
        'backfilling' => 'Doplňování',
        'scanning' => 'Skenování',
        'rate_limited' => 'Omezený počet dotazů',
        'needs_reauth' => 'Vyžaduje nové přihlášení',
        'error' => 'Chyba',
    ],

    'retry_seconds' => 'další pokus za :ns',
    'retry_minutes' => 'další pokus za :nmin',
    'retry_hours' => 'další pokus za :nh',

    'reconnect' => 'Připojit znovu',
    'disconnect' => 'Odpojit',
    'scan_now' => 'Skenovat',
    'scan_in_progress_title' => 'Skenování už běží',

    'add_another' => 'Přidat další schránku',
    'gmail_card_body' => 'Připoj účet Gmail, ať ho Beatrax může skenovat kvůli účtenkám.',
    'microsoft_card_body' => 'Připoj účet Microsoft 365 nebo Outlook.com, ať ho Beatrax může skenovat kvůli účtenkám.',

    'discovered_heading' => 'Nalezení odesílatelé',
    'discovered_body' => 'Odesílatelé, kteří vypadají, že posílají účtenky, ale zatím nejsou na seznamu známých. Přidej ty, které má Beatrax skenovat; zbytek zamítni.',
    'last_seen' => 'naposledy viděno',
    'seen_times' => 'Počet výskytů: :count|Počet výskytů: :count|Počet výskytů: :count',
    'add' => 'Přidat',
    'add_aria' => 'Přidat :email',
    'dismiss' => 'Zamítnout',
    'dismiss_aria' => 'Zamítnout :email',

    'toast' => [
        'scan_in_progress' => 'Skenování už běží.',
        'scan_started' => 'Skenování spuštěno.',
        'sender_added' => 'Odesílatel přidán.',
        'sender_dismissed' => 'Odesílatel zamítnut.',
    ],
];
