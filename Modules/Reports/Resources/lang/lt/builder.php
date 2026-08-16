<?php

declare(strict_types=1);

return [
    'title' => 'Ataskaitos',
    'page_title' => 'Ataskaitos · Beatrax',
    'subtitle' => 'Sudaryk ataskaitą iš savo didžiosios knygos.',
    'controls_aria' => 'Ataskaitos valdikliai',
    'result_aria' => 'Ataskaitos rezultatas',
    'dismiss' => 'Slėpti',

    'metric' => [
        'heading' => 'Rodiklis',
        'spend' => 'Išlaidos',
        'income' => 'Pajamos',
        'net' => 'Grynasis',
        'net_worth' => 'Grynoji vertė',
        'fallback' => 'Suma',
    ],

    'group_by' => 'Grupuoti pagal',

    'dimension' => [
        'category' => 'Kategorija',
        'time_bucket' => 'Laiko intervalas',
        'counterparty' => 'Kita šalis',
        'account' => 'Sąskaita',
    ],

    'period' => [
        'heading' => 'Laikotarpis',
        'this_month' => 'Šis mėnuo',
        'last_3_months' => 'Paskutiniai 3 mėnesiai',
        'last_6_months' => 'Paskutiniai 6 mėnesiai',
        'last_12_months' => 'Paskutiniai 12 mėnesių',
        'ytd' => 'Nuo metų pradžios',
        'this_year' => 'Šie metai',
        'custom' => 'Pasirinktas laikotarpis',
        'from' => 'Nuo',
        'to' => 'Iki',
    ],

    'currency' => [
        'heading' => 'Valiuta',
        'aria' => 'Valiutos režimas',
        'base' => 'Bazinė',
        'original' => 'Originali',
    ],

    'granularity' => [
        'heading' => 'Detalumas',
        'aria' => 'Laiko detalumas',
        'monthly' => 'Kas mėnesį',
        'weekly' => 'Kas savaitę',
    ],

    'filters' => [
        'heading' => 'Filtrai',
    ],

    'compare' => 'Palyginti su ankstesniu laikotarpiu',

    'viz' => [
        'heading' => 'Vaizdavimas',
        'table' => 'Lentelė',
        'bar' => 'Stulpelinė',
        'line' => 'Linijinė',
        'donut' => 'Žiedinė',
    ],

    'actions' => [
        'update_report' => 'Atnaujinti ataskaitą',
        'save_report' => 'Išsaugoti ataskaitą',
        'report_name' => 'Ataskaitos pavadinimas',
        'update' => 'Atnaujinti',
        'save' => 'Išsaugoti',
        'cancel' => 'Atšaukti',
        'export_csv' => 'Eksportuoti CSV',
    ],

    'updating' => '… Atnaujinama',

    'empty' => [
        'heading' => 'Su šiuo pasirinkimu nėra ką rodyti',
        'body' => 'Pabandyk išplėsti datų intervalą arba pašalinti filtrą.',
    ],

    'total_prefix' => 'Iš viso',
    'total' => 'Iš viso',
    'vs_previous' => 'palyginti su ankstesniu laikotarpiu',
    'view_transactions' => 'Peržiūrėti operacijas',

    'fx_excluded' => ':count sąskaita neperskaičiuota — nėra kurso|:count sąskaitos neperskaičiuotos — nėra kurso|:count sąskaitų neperskaičiuota — nėra kurso',

    'group_header' => [
        'category' => 'Kategorija',
        'counterparty' => 'Kita šalis',
        'account' => 'Sąskaita',
        'month' => 'Mėnuo',
        'default' => 'Grupė',
    ],

    'chart' => [
        'bar_title' => 'Spustelėk stulpelį, kad pamatytum jo operacijas',
        'line_title' => 'Spustelėk tašką, kad pamatytum jo operacijas',
        'donut_title' => 'Spustelėk segmentą, kad pamatytum jo operacijas',
    ],

    'flash' => [
        'saved' => 'Ataskaita išsaugota.',
        'updated' => 'Ataskaita atnaujinta.',
    ],

    'filter' => [
        'account' => 'Sąskaita',
        'account_count' => ':count sąskaita|:count sąskaitos|:count sąskaitų',
        'remove_account' => 'Pašalinti sąskaitos filtrą',
        'account_dialog' => 'Sąskaitos filtras',

        'category' => 'Kategorija',
        'category_count' => ':count kategorija|:count kategorijos|:count kategorijų',
        'remove_category' => 'Pašalinti kategorijos filtrą',
        'category_dialog' => 'Kategorijos filtras',

        'counterparty' => 'Kita šalis',
        'counterparty_count' => ':count kita šalis|:count kitos šalys|:count kitų šalių',
        'remove_counterparty' => 'Pašalinti kitos šalies filtrą',
        'counterparty_dialog' => 'Kitos šalies filtras',

        'amount' => 'Suma',
        'remove_amount' => 'Pašalinti sumos filtrą',
        'amount_dialog' => 'Sumos filtras',
        'dir_both' => 'Abi',
        'dir_in' => 'Pajamos',
        'dir_out' => 'Išlaidos',
        'min' => 'Min.',
        'max' => 'Maks.',
        'min_aria' => 'Mažiausia suma',
        'max_aria' => 'Didžiausia suma',
    ],
];
