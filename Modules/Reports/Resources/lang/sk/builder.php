<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Bez kategórie',
    'title' => 'Zostavy',
    'page_title' => 'Zostavy · Beatrax',
    'subtitle' => 'Zlož si zostavu zo svojej knihy.',
    'controls_aria' => 'Ovládanie zostavy',
    'result_aria' => 'Výsledok zostavy',
    'dismiss' => 'Zamietnuť',

    'metric' => [
        'heading' => 'Metrika',
        'spend' => 'Výdavky',
        'income' => 'Príjmy',
        'net' => 'Netto',
        'net_worth' => 'Čisté imanie',
        'fallback' => 'Suma',
    ],

    'group_by' => 'Zoskupiť podľa',

    'dimension' => [
        'category' => 'Kategória',
        'time_bucket' => 'Časové obdobie',
        'counterparty' => 'Protistrana',
        'account' => 'Účet',
    ],

    'period' => [
        'heading' => 'Obdobie',
        'this_month' => 'Tento mesiac',
        'last_3_months' => 'Posledné 3 mesiace',
        'last_6_months' => 'Posledných 6 mesiacov',
        'last_12_months' => 'Posledných 12 mesiacov',
        'ytd' => 'Od začiatku roka',
        'this_year' => 'Tento rok',
        'custom' => 'Vlastný rozsah',
        'from' => 'Od',
        'to' => 'Do',
    ],

    'currency' => [
        'heading' => 'Mena',
        'aria' => 'Režim meny',
        'base' => 'Základná',
        'original' => 'Pôvodná',
    ],

    'granularity' => [
        'heading' => 'Podrobnosť',
        'aria' => 'Časová podrobnosť',
        'monthly' => 'Mesačne',
        'weekly' => 'Týždenne',
    ],

    'filters' => [
        'heading' => 'Filtre',
    ],

    'compare' => 'Porovnať s predchádzajúcim obdobím',

    'viz' => [
        'heading' => 'Vizualizácia',
        'table' => 'Tabuľka',
        'bar' => 'Stĺpcový',
        'line' => 'Čiarový',
        'donut' => 'Prstencový',
    ],

    'actions' => [
        'update_report' => 'Aktualizovať zostavu',
        'save_report' => 'Uložiť zostavu',
        'report_name' => 'Názov zostavy',
        'update' => 'Aktualizovať',
        'save' => 'Uložiť',
        'cancel' => 'Zrušiť',
        'export_csv' => 'Exportovať CSV',
    ],

    'updating' => '… Aktualizuje sa',

    'empty' => [
        'heading' => 'Pre tento výber nie je čo zobraziť',
        'body' => 'Skús rozšíriť rozsah dátumov alebo odstrániť filter.',
    ],

    'total_prefix' => 'Spolu',
    'total' => 'Spolu',
    'vs_previous' => 'oproti predchádzajúcemu obdobiu',
    'view_transactions' => 'Zobraziť transakcie',

    'fx_excluded' => ':count účet neprevedený — kurz nie je dostupný|:count účty neprevedené — kurz nie je dostupný|:count účtov neprevedených — kurz nie je dostupný',

    'group_header' => [
        'category' => 'Kategória',
        'counterparty' => 'Protistrana',
        'account' => 'Účet',
        'month' => 'Mesiac',
        'default' => 'Skupina',
    ],

    'chart' => [
        'bar_title' => 'Kliknutím na stĺpec zobrazíš jeho transakcie',
        'line_title' => 'Kliknutím na bod zobrazíš jeho transakcie',
        'donut_title' => 'Kliknutím na segment zobrazíš jeho transakcie',
    ],

    'flash' => [
        'saved' => 'Zostava uložená.',
        'updated' => 'Zostava aktualizovaná.',
    ],

    'filter' => [
        'account' => 'Účet',
        'account_count' => ':count účet|:count účty|:count účtov',
        'remove_account' => 'Odstrániť filter účtu',
        'account_dialog' => 'Filter účtu',

        'category' => 'Kategória',
        'category_count' => ':count kategória|:count kategórie|:count kategórií',
        'remove_category' => 'Odstrániť filter kategórie',
        'category_dialog' => 'Filter kategórie',

        'counterparty' => 'Protistrana',
        'counterparty_count' => ':count protistrana|:count protistrany|:count protistrán',
        'remove_counterparty' => 'Odstrániť filter protistrany',
        'counterparty_dialog' => 'Filter protistrany',

        'amount' => 'Suma',
        'remove_amount' => 'Odstrániť filter sumy',
        'amount_dialog' => 'Filter sumy',
        'dir_both' => 'Oboje',
        'dir_in' => 'Príjmy',
        'dir_out' => 'Výdavky',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minimálna suma',
        'max_aria' => 'Maximálna suma',
    ],

    'other_movement' => 'Poplatky a úpravy (nezapočítané vyššie)',
];
