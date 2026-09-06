<?php

declare(strict_types=1);

return [
    'heading' => 'Schránky',
    'intro' => 'Připoj schránky Gmail a Microsoft 365, ať je Beatrax může skenovat kvůli účtenkám.',
    'intro_phone' => 'Skenování schránek běží v aplikaci pro počítač, ne v tomto telefonu.',

    'phone_heading' => 'Tento telefon schránky neskenuje',
    'phone_body' => 'Připoj Gmail nebo Microsoft 365 v aplikaci pro počítač — účtenky, které tam najde, sem dorazí přes synchronizaci.',
    'connection_canceled' => 'Připojení zrušeno.',
    'connection_failed' => 'Připojení se nepodařilo dokončit.',

    'backfilling' => 'Doplňování',
    'backfill_progress' => ':fetched / ~:count zpráva|:fetched / ~:count zprávy|:fetched / ~:count zpráv',

    'connect_heading' => 'Připoj svůj e-mail',
    'connect_body' => 'Importuj účtenky z PayPalu, ICS Cards, Google Play i od dalších obchodníků tím, že Beatraxu dáš přístup jen pro čtení k jedné nebo více svým schránkám.',
    'connect_body_phone' => 'Účtenky z PayPalu, ICS Cards, Google Play i od dalších obchodníků importuje aplikace pro počítač ze schránek, ke kterým jí dáš přístup jen pro čtení. Tento telefon ukazuje, co ten import najde.',
    'connect_gmail' => 'Připojit Gmail',
    'connect_microsoft' => 'Připojit Microsoft 365',
    'readonly_note' => 'Beatrax zprávy pouze čte. Ve schránce nikdy nic neodesílá, neoznačuje, nepřesouvá ani nemaže.',

    'months' => ':count měs.|:count měs.|:count měs.',
    'not_scanned_yet' => 'zatím neskenováno',
    'not_scanned_yet_phone' => 'v tomto telefonu neskenováno',
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

    'error_detail' => 'Poslední skenování se nedokončilo. Zkuste Skenovat nebo tuto schránku připojte znovu.',
    'oauth_state_mismatch' => 'Tento odkaz pro připojení vypršel nebo již byl použit. Začni připojení znovu.',
    'oauth_client_missing' => 'Jednorázové nastavení pro tohoto poskytovatele pošty není v tomto zařízení dokončené, takže zatím není s čím se připojit. Stiskni znovu Připojit a dokonči ho.',
    'oauth_no_code' => 'Poskytovatel pošty tě vrátil bez kódu, který Beatrax potřebuje k dokončení, takže se nepřipojila žádná schránka. Začni připojení znovu.',
    'oauth_grant_refused' => 'Poskytovatel pošty odmítl oprávnění udělené Beatrax — vypršelo nebo bylo odebráno. Začni připojení znovu a udělej ho.',
    'oauth_exchange_failed' => 'Poskytovatel pošty připojení nedokončil, takže se nepřidala žádná schránka. Zkus to za pár minut znovu.',
    'oauth_not_saved' => 'Připojení se nepodařilo uložit do tohoto zařízení, takže se nepřidala žádná schránka. Zkus to znovu — pokud selhává dál, protokol aplikace zaznamenává, co ho zastavilo.',
    'oauth_no_offline_access_google' => 'Google neudělil trvalé oprávnění, které Beatrax potřebuje, takže by tato schránka do hodiny přestala být prohledávána. Zveřejni svou obrazovku souhlasu OAuth do produkce a připoj ji znovu.',
    'oauth_no_offline_access' => 'Poskytovatel pošty neudělil trvalé oprávnění, které Beatrax potřebuje, takže by tato schránka do hodiny přestala být prohledávána. Připoj ji znovu a při dotazu povol offline přístup.',
    'oauth_no_offline_access_google_phone' => 'Google neudělil trvalé oprávnění, které Beatrax potřebuje, takže se nepřipojila žádná schránka. Zveřejni svou obrazovku souhlasu OAuth do produkce a připoj ji znovu — samotné skenování běží v aplikaci pro počítač.',
    'oauth_no_offline_access_phone' => 'Poskytovatel pošty neudělil trvalé oprávnění, které Beatrax potřebuje, takže se nepřipojila žádná schránka. Připoj ji znovu a při dotazu povol offline přístup — samotné skenování běží v aplikaci pro počítač.',

    'retry_seconds' => 'další pokus za :ns',
    'retry_minutes' => 'další pokus za :nmin',
    'retry_hours' => 'další pokus za :nh',

    'reconnect' => 'Připojit znovu',
    'disconnect' => 'Odpojit',
    'disconnect_confirm' => 'Odpojit :email? Odstraní to uložené přihlašovací údaje této schránky, její historii skenování i odesílatele, které jsi přidal nebo zamítl. Účtenky, které už jsou v Beatraxu, to neovlivní. Opětovné připojení začne skenovat od začátku.',
    'scan_now' => 'Skenovat',
    'scan_in_progress_title' => 'Skenování už běží',

    'add_another' => 'Přidat další schránku',
    'gmail_card_body' => 'Připoj účet Gmail, ať ho Beatrax může skenovat kvůli účtenkám.',
    'microsoft_card_body' => 'Připoj účet Microsoft 365 nebo Outlook.com, ať ho Beatrax může skenovat kvůli účtenkám.',
    'gmail_card_body_phone' => 'Gmail skenuje aplikace pro počítač. Připoj ho tam — tenhle telefon ukazuje, co najde.',
    'microsoft_card_body_phone' => 'Microsoft 365 a Outlook.com skenuje aplikace pro počítač. Připoj je tam — tenhle telefon ukazuje, co najde.',

    'discovered_heading' => 'Nalezení odesílatelé',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (výpisy)',
    ],
    'discovered_body' => 'Odesílatelé, kteří vypadají, že posílají účtenky, ale zatím nejsou na seznamu známých. Přidej ty, které má Beatrax skenovat; zbytek zamítni.',
    'last_seen' => 'naposledy viděno',
    'seen_times' => 'Počet výskytů: :count|Počet výskytů: :count|Počet výskytů: :count',
    'add' => 'Přidat',
    'add_aria' => 'Přidat :email',
    'dismiss' => 'Zamítnout',
    'dismiss_aria' => 'Zamítnout :email',

    'toast' => [
        'reconnect_first' => 'Před skenováním tuto schránku znovu připojte.',
        'scan_in_progress' => 'Skenování už běží.',
        'scan_started' => 'Skenování spuštěno.',
        'sender_added' => 'Odesílatel přidán.',
        'sender_dismissed' => 'Odesílatel zamítnut.',
    ],
];
