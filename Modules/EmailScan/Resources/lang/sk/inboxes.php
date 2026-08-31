<?php

declare(strict_types=1);

return [
    'heading' => 'Schránky',
    'intro' => 'Pripoj schránky Gmail a Microsoft 365, aby ich Beatrax mohol prehľadávať a hľadať v nich účtenky.',
    'intro_phone' => 'Prehľadávanie schránok beží v aplikácii pre počítač, nie v tomto telefóne.',

    'phone_heading' => 'Tento telefón schránky neprehľadáva',
    'phone_body' => 'Pripoj Gmail alebo Microsoft 365 v aplikácii pre počítač — účtenky, ktoré tam nájde, sem dorazia cez synchronizáciu.',
    'connection_canceled' => 'Pripojenie zrušené.',
    'connection_failed' => 'Pripojenie sa nepodarilo dokončiť.',

    'backfilling' => 'Dopĺňanie histórie',
    'backfill_progress' => ':fetched / ~:count správa|:fetched / ~:count správy|:fetched / ~:count správ',

    'connect_heading' => 'Pripoj svoj e-mail',
    'connect_body' => 'Importuj účtenky z PayPal, ICS Cards, Google Play a od ďalších obchodníkov tak, že aplikácii Beatrax povolíš prístup na čítanie k jednej alebo viacerým schránkam.',
    'connect_body_phone' => 'Účtenky z PayPal, ICS Cards, Google Play a od ďalších obchodníkov importuje aplikácia pre počítač zo schránok, ku ktorým jej povolíš prístup na čítanie. Tento telefón ukazuje, čo ten import nájde.',
    'connect_gmail' => 'Pripojiť Gmail',
    'connect_microsoft' => 'Pripojiť Microsoft 365',
    'readonly_note' => 'Beatrax správy iba číta. V tvojej schránke nikdy nič neodosiela, neoznačuje, nepresúva ani nemaže.',

    'months' => ':count mes.|:count mes.|:count mes.',
    'not_scanned_yet' => 'zatiaľ neprehľadané',
    'not_scanned_yet_phone' => 'v tomto telefóne neprehľadané',
    'last_scanned' => 'naposledy prehľadané',
    'window_prefix' => 'Obdobie:',
    'edit' => 'Upraviť',

    'badge' => [
        'idle' => 'Nečinné',
        'backfilling' => 'Dopĺňanie histórie',
        'scanning' => 'Prehľadávanie',
        'rate_limited' => 'Limit požiadaviek',
        'needs_reauth' => 'Vyžaduje opätovné prihlásenie',
        'error' => 'Chyba',
    ],

    'error_detail' => 'Posledné prehľadávanie sa nedokončilo. Skúste Prehľadať teraz alebo túto schránku pripojte znova.',
    'oauth_no_code' => 'Poskytovateľ pošty ťa vrátil bez kódu, ktorý Beatrax potrebuje na dokončenie, takže sa nepripojila žiadna schránka. Začni pripojenie znova.',
    'oauth_grant_refused' => 'Poskytovateľ pošty odmietol oprávnenie udelené Beatrax — vypršalo alebo bolo odobraté. Začni pripojenie znova a udeľ ho.',
    'oauth_exchange_failed' => 'Poskytovateľ pošty pripojenie nedokončil, takže sa nepridala žiadna schránka. Skús to o pár minút znova.',
    'oauth_not_saved' => 'Pripojenie sa nepodarilo uložiť do tohto zariadenia, takže sa nepridala žiadna schránka. Skús to znova — ak zlyháva ďalej, protokol aplikácie zaznamenáva, čo ho zastavilo.',
    'oauth_no_offline_access_google' => 'Google neudelil trvalé oprávnenie, ktoré Beatrax potrebuje, takže by táto schránka do hodiny prestala byť prehľadávaná. Zverejni svoju obrazovku súhlasu OAuth do produkcie a pripoj ju znova.',
    'oauth_no_offline_access' => 'Poskytovateľ pošty neudelil trvalé oprávnenie, ktoré Beatrax potrebuje, takže by táto schránka do hodiny prestala byť prehľadávaná. Pripoj ju znova a pri otázke povoľ offline prístup.',
    'oauth_no_offline_access_google_phone' => 'Google neudelil trvalé oprávnenie, ktoré Beatrax potrebuje, takže sa nepripojila žiadna schránka. Zverejni svoju obrazovku súhlasu OAuth do produkcie a pripoj ju znova — samotné prehľadávanie beží v aplikácii pre počítač.',
    'oauth_no_offline_access_phone' => 'Poskytovateľ pošty neudelil trvalé oprávnenie, ktoré Beatrax potrebuje, takže sa nepripojila žiadna schránka. Pripoj ju znova a pri otázke povoľ offline prístup — samotné prehľadávanie beží v aplikácii pre počítač.',

    'retry_seconds' => 'ďalší pokus o :ns',
    'retry_minutes' => 'ďalší pokus o :nmin',
    'retry_hours' => 'ďalší pokus o :nh',

    'reconnect' => 'Pripojiť znova',
    'disconnect' => 'Odpojiť',
    'scan_now' => 'Prehľadať teraz',
    'scan_in_progress_title' => 'Prehľadávanie už prebieha',

    'add_another' => 'Pridať ďalšiu schránku',
    'gmail_card_body' => 'Pripoj účet Gmail, aby ho Beatrax mohol prehľadávať a hľadať v ňom účtenky.',
    'microsoft_card_body' => 'Pripoj účet Microsoft 365 alebo Outlook.com, aby ho Beatrax mohol prehľadávať a hľadať v ňom účtenky.',
    'gmail_card_body_phone' => 'Gmail prehľadáva aplikácia pre počítač. Účet pripojený tu sa nikdy neprehľadáva sám od seba.',
    'microsoft_card_body_phone' => 'Microsoft 365 a Outlook.com prehľadáva aplikácia pre počítač. Účet pripojený tu sa nikdy neprehľadáva sám od seba.',

    'discovered_heading' => 'Objavení odosielatelia',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (výpisy)',
    ],
    'discovered_body' => 'Odosielatelia, ktorí vyzerajú, že posielajú účtenky, ale zatiaľ nie sú v tvojom zozname známych odosielateľov. Pridaj tých, ktorých má Beatrax prehľadávať; ostatných zamietni.',
    'last_seen' => 'naposledy videné',
    'seen_times' => 'Počet výskytov: :count|Počet výskytov: :count|Počet výskytov: :count',
    'add' => 'Pridať',
    'add_aria' => 'Pridať :email',
    'dismiss' => 'Zamietnuť',
    'dismiss_aria' => 'Zamietnuť :email',

    'toast' => [
        'reconnect_first' => 'Pred skenovaním znova pripojte túto schránku.',
        'scan_in_progress' => 'Prehľadávanie už prebieha.',
        'scan_started' => 'Prehľadávanie sa spustilo.',
        'sender_added' => 'Odosielateľ pridaný.',
        'sender_dismissed' => 'Odosielateľ zamietnutý.',
    ],
];
