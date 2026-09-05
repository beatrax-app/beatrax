<?php

declare(strict_types=1);

return [
    'page_title' => 'Údaje a zariadenia',
    'heading' => 'Údaje a zariadenia',
    'sync_status' => 'Stav synchronizácie',
    'syncing_progress' => 'Synchronizuje sa… :count záznam|Synchronizuje sa… :count záznamy|Synchronizuje sa… :count záznamov',
    'initial_sync_aria' => 'Priebeh prvej synchronizácie',
    'no_peers' => 'Spáruj ďalšie zariadenie a spustí sa synchronizácia.',
    'sync_now' => 'Synchronizovať teraz',
    'result' => [
        'synced' => 'Synchronizované s druhým zariadením.',
        'unreachable' => 'Druhé zariadenie je nedostupné — skontroluj, či sú obe v rovnakej sieti.',
        'locked' => 'Odomkni aplikáciu a spusť synchronizáciu.',
        'not_enabled' => 'Synchronizácia na tomto zariadení zatiaľ nie je nastavená.',
        'unreadable' => 'Kľúč tohto zariadenia sa už nedá otvoriť. Spáruj znova a obnov synchronizáciu.',
        'paused_on_cellular' => 'Pozastavené — synchronizácia je obmedzená na Wi-Fi a si na mobilných dátach.',
    ],
    'background_note' => 'Beatrax počúva po celý čas, kým je otvorený, takže spárované zariadenie sa s týmto môže synchronizovať kedykoľvek. Synchronizovať teraz spustí výmenu dát z tejto strany.',
    'background_note_phone' => 'Synchronizácia prebehne, keď klepneš na Synchronizovať teraz. Na pozadí bežať nemôže — zámok aplikácie drží jediný kľúč.',
    'network' => 'Sieť',
    'pause_cellular' => 'Pozastaviť synchronizáciu na mobilných dátach',
    'pause_cellular_help' => 'Predvolene vypnuté — synchronizácia funguje všade. Zapni, ak chceš synchronizovať len cez Wi-Fi.',
];
