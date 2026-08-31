<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvůj účet PayPal',
    'h1' => 'Připoj svůj účet PayPal',

    'lede_html' => 'Vlož export pohybů z PayPalu — jeden řádek na transakci, ne souhrn zůstatku. PayPal pojmenovává své přehledy v jazyce tvého účtu a zatím čteme nizozemskou dvojici: <em lang="nl">Rapport Transactiegegevens</em>, ne <span lang="nl">Saldorapport</span>. Pokud ti vyjde v jiném jazyce, přepni PayPal do nizozemštiny, než ho stáhneš.',

    'format_group_aria' => 'PayPal exportuje jen do CSV',
    'got_it_as' => 'Mám ho jako:',
    'badge_only_format' => 'jediný formát',

    'mini' => [
        'login_label' => 'Přihlášení',
        'custom_label' => 'Vlastní výpisy',
        'range_label' => 'Vyber rozsah',
        'range_sub' => 'Posledních 12 měsíců',
        'download_label' => 'Stáhnout jako CSV',
    ],

    'drop_lead' => 'Vlož sem export pohybů',
    'browse_file' => 'nebo vyber soubor',

    'file_ready' => '· ✓ připraveno',

    'skip' => 'Přeskočit tento krok',
    'continue' => 'Pokračovat →',

    'errors' => [
        'required' => 'Nejdřív do pole vlož export pohybů z PayPalu.',
        'max' => 'Tenhle soubor je příliš velký. Export pohybů z PayPalu mívá výrazně méně než 10 MB.',
        'extensions' => 'Tenhle soubor nevypadá jako CSV z PayPalu. Stáhni si export pohybů — jeden řádek na transakci, ne souhrn zůstatku — ve formátu CSV.',
        'unreadable' => 'Tenhle soubor se nepodařilo přečíst. Celou chybu najdeš v /dev/logs.',
    ],
];
