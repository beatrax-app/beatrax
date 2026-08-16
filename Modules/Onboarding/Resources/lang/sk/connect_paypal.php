<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoj účet PayPal',
    'h1' => 'Prepoj svoj účet PayPal',

    'lede_html' => 'Vlož export s podrobnosťami transakcií z PayPalu — v holandskom účte PayPal je uvedený ako <em lang="nl">Rapport Transactiegegevens</em>. Výkaz zostatku (<span lang="nl">Saldorapport</span>) nestačí — potrebujeme údaje o jednotlivých udalostiach.',

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

    'drop_lead' => 'Sem vlož CSV s podrobnosťami transakcií',
    'browse_file' => 'alebo vyhľadaj súbor',

    'file_ready' => '· ✓ pripravené',

    'skip' => 'Preskočiť tento krok',
    'continue' => 'Pokračovať →',

    'errors' => [
        'required' => 'Najprv vlož do poľa CSV súbor PayPal Rapport Transactiegegevens.',
        'max' => 'Tento súbor je príliš veľký. Exporty PayPal Rapport Transactiegegevens majú zvyčajne výrazne menej ako 10 MB.',
        'extensions' => 'Tento súbor nevyzerá ako CSV z PayPalu. Stiahni si z PayPalu report Rapport Transactiegegevens (nie výkaz zostatku Saldorapport) vo formáte CSV.',
        'unreadable' => 'Tento súbor sa nepodarilo prečítať. Úplná chyba je v /dev/logs.',
    ],
];
