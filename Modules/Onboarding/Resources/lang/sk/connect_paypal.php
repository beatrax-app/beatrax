<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoj účet PayPal',
    'h1' => 'Prepoj svoj účet PayPal',

    'lede_html' => 'Vlož export pohybov z PayPalu — jeden riadok na transakciu, nie súhrn zostatku. PayPal pomenúva svoje výkazy v jazyku tvojho účtu a zatiaľ čítame holandskú dvojicu: <em lang="nl">Rapport Transactiegegevens</em>, nie <span lang="nl">Saldorapport</span>. Ak ti vyjde v inom jazyku, pred stiahnutím prepni PayPal do holandčiny.',

    'format_group_aria' => 'PayPal exportuje len do CSV',
    'got_it_as' => 'Mám ho ako:',
    'badge_only_format' => 'jediný formát',

    'mini' => [
        'login_label' => 'Prihlás sa',
        'custom_label' => 'Vlastné výpisy',
        'range_label' => 'Vyber obdobie',
        'range_sub' => 'Posledných 12 mesiacov',
        'download_label' => 'Stiahni ako CSV',
    ],

    'drop_lead' => 'Vlož sem svoj export pohybov',
    'browse_file' => 'alebo vyhľadaj súbor',

    'file_ready' => '· ✓ pripravené',

    'skip' => 'Preskočiť tento krok',
    'continue' => 'Pokračovať →',

    'errors' => [
        'required' => 'Najprv vlož do poľa export pohybov z PayPalu.',
        'max' => 'Tento súbor je príliš veľký. Export pohybov z PayPalu máva výrazne menej ako 10 MB.',
        'extensions' => 'Tento súbor nevyzerá ako CSV z PayPalu. Stiahni si export pohybov — jeden riadok na transakciu, nie súhrn zostatku — vo formáte CSV.',
        'unreadable' => 'Tento súbor sa nepodarilo prečítať. Úplná chyba je v /dev/logs.',
    ],
];
