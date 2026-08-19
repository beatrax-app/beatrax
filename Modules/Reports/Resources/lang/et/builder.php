<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Kategoriseerimata',
    'title' => 'Aruanded',
    'page_title' => 'Aruanded · Beatrax',
    'subtitle' => 'Koosta oma pearaamatust aruanne.',
    'controls_aria' => 'Aruande juhtnupud',
    'result_aria' => 'Aruande tulemus',
    'dismiss' => 'Peida',

    'metric' => [
        'heading' => 'Näitaja',
        'spend' => 'Kulud',
        'income' => 'Tulud',
        'net' => 'Neto',
        'net_worth' => 'Netoväärtus',
        'fallback' => 'Summa',
    ],

    'group_by' => 'Rühmita',

    'dimension' => [
        'category' => 'Kategooria',
        'time_bucket' => 'Ajavahemik',
        'counterparty' => 'Vastaspool',
        'account' => 'Konto',
    ],

    'period' => [
        'heading' => 'Periood',
        'this_month' => 'See kuu',
        'last_3_months' => 'Viimased 3 kuud',
        'last_6_months' => 'Viimased 6 kuud',
        'last_12_months' => 'Viimased 12 kuud',
        'ytd' => 'Aasta algusest',
        'this_year' => 'See aasta',
        'custom' => 'Kohandatud vahemik',
        'from' => 'Alates',
        'to' => 'Kuni',
    ],

    'currency' => [
        'heading' => 'Valuuta',
        'aria' => 'Valuutarežiim',
        'base' => 'Põhivaluuta',
        'original' => 'Algne',
    ],

    'granularity' => [
        'heading' => 'Täpsusaste',
        'aria' => 'Ajaline täpsusaste',
        'monthly' => 'Kuude kaupa',
        'weekly' => 'Nädalate kaupa',
    ],

    'filters' => [
        'heading' => 'Filtrid',
    ],

    'compare' => 'Võrdle eelmise perioodiga',

    'viz' => [
        'heading' => 'Visualiseering',
        'table' => 'Tabel',
        'bar' => 'Tulpdiagramm',
        'line' => 'Joondiagramm',
        'donut' => 'Sõõrdiagramm',
    ],

    'actions' => [
        'update_report' => 'Uuenda aruannet',
        'save_report' => 'Salvesta aruanne',
        'report_name' => 'Aruande nimi',
        'update' => 'Uuenda',
        'save' => 'Salvesta',
        'cancel' => 'Tühista',
        'export_csv' => 'Ekspordi CSV',
    ],

    'updating' => '… Uuendan',

    'empty' => [
        'heading' => 'Selle valiku kohta pole midagi näidata',
        'body' => 'Proovi laiendada kuupäevavahemikku või eemaldada mõni filter.',
    ],

    'total_prefix' => 'Kokku',
    'total' => 'Kokku',
    'vs_previous' => 'vs eelmine periood',
    'view_transactions' => 'Vaata tehinguid',

    'fx_excluded' => ':count kontot ei teisendatud — kurss puudub|:count kontot ei teisendatud — kurss puudub',

    'group_header' => [
        'category' => 'Kategooria',
        'counterparty' => 'Vastaspool',
        'account' => 'Konto',
        'month' => 'Kuu',
        'default' => 'Rühm',
    ],

    'chart' => [
        'bar_title' => 'Klõpsa tulbal, et näha selle tehinguid',
        'line_title' => 'Klõpsa punktil, et näha selle tehinguid',
        'donut_title' => 'Klõpsa segmendil, et näha selle tehinguid',
    ],

    'flash' => [
        'saved' => 'Aruanne on salvestatud.',
        'updated' => 'Aruanne on uuendatud.',
    ],

    'filter' => [
        'account' => 'Konto',
        'account_count' => ':count konto|:count kontot',
        'remove_account' => 'Eemalda kontofilter',
        'account_dialog' => 'Kontofilter',

        'category' => 'Kategooria',
        'category_count' => ':count kategooria|:count kategooriat',
        'remove_category' => 'Eemalda kategooriafilter',
        'category_dialog' => 'Kategooriafilter',

        'counterparty' => 'Vastaspool',
        'counterparty_count' => ':count vastaspool|:count vastaspoolt',
        'remove_counterparty' => 'Eemalda vastaspoole filter',
        'counterparty_dialog' => 'Vastaspoole filter',

        'amount' => 'Summa',
        'remove_amount' => 'Eemalda summafilter',
        'amount_dialog' => 'Summafilter',
        'dir_both' => 'Mõlemad',
        'dir_in' => 'Sisse',
        'dir_out' => 'Välja',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Miinimumsumma',
        'max_aria' => 'Maksimumsumma',
    ],
];
