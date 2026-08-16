<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvůj účet PayPal',
    'h1' => 'Připoj svůj účet PayPal',

    'lede_html' => 'Vlož export s podrobnostmi transakcí z PayPal — v nizozemském účtu PayPal je vedený jako <em lang="nl">Rapport Transactiegegevens</em>. Přehled zůstatku (<span lang="nl">Saldorapport</span>) nefunguje — potřebujeme data po jednotlivých událostech.',

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

    'drop_lead' => 'Sem vlož CSV s podrobnostmi transakcí',
    'browse_file' => 'nebo vyber soubor',

    'file_ready' => '· ✓ připraveno',

    'skip' => 'Přeskočit tento krok',
    'continue' => 'Pokračovat →',

    'errors' => [
        'required' => 'Nejdřív do pole vlož CSV soubor PayPal Rapport Transactiegegevens.',
        'max' => 'Tenhle soubor je příliš velký. Exporty PayPal Rapport Transactiegegevens mívají výrazně méně než 10 MB.',
        'extensions' => 'Tenhle soubor nevypadá jako CSV z PayPal. Stáhni si z PayPal report Rapport Transactiegegevens (ne přehled zůstatku Saldorapport) ve formátu CSV.',
        'unreadable' => 'Tenhle soubor se nepodařilo přečíst. Celou chybu najdeš v /dev/logs.',
    ],
];
