<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Bez kategorijas',
    'title' => 'Atskaites',
    'page_title' => 'Atskaites · Beatrax',
    'subtitle' => 'Izveidojiet atskaiti no savas virsgrāmatas.',
    'controls_aria' => 'Atskaites vadīklas',
    'result_aria' => 'Atskaites rezultāts',
    'dismiss' => 'Aizvērt',

    'metric' => [
        'heading' => 'Rādītājs',
        'spend' => 'Tēriņi',
        'income' => 'Ieņēmumi',
        'net' => 'Neto',
        'net_worth' => 'Neto vērtība',
        'fallback' => 'Summa',
    ],

    'group_by' => 'Grupēt pēc',

    'dimension' => [
        'category' => 'Kategorijas',
        'time_bucket' => 'Laika intervāla',
        'counterparty' => 'Darījuma partnera',
        'account' => 'Konta',
    ],

    'period' => [
        'heading' => 'Periods',
        'this_month' => 'Šis mēnesis',
        'last_3_months' => 'Pēdējie 3 mēneši',
        'last_6_months' => 'Pēdējie 6 mēneši',
        'last_12_months' => 'Pēdējie 12 mēneši',
        'ytd' => 'Kopš gada sākuma',
        'this_year' => 'Šis gads',
        'custom' => 'Pielāgots periods',
        'from' => 'No',
        'to' => 'Līdz',
    ],

    'currency' => [
        'heading' => 'Valūta',
        'aria' => 'Valūtas režīms',
        'base' => 'Pārskata',
        'original' => 'Sākotnējā',
    ],

    'granularity' => [
        'heading' => 'Detalizācija',
        'aria' => 'Laika detalizācija',
        'monthly' => 'Pa mēnešiem',
        'weekly' => 'Pa nedēļām',
    ],

    'filters' => [
        'heading' => 'Filtri',
    ],

    'compare' => 'Salīdzināt ar iepriekšējo periodu',

    'viz' => [
        'heading' => 'Attēlojums',
        'table' => 'Tabula',
        'bar' => 'Stabiņi',
        'line' => 'Līnija',
        'donut' => 'Riņķis',
    ],

    'actions' => [
        'update_report' => 'Atjaunināt atskaiti',
        'save_report' => 'Saglabāt atskaiti',
        'report_name' => 'Atskaites nosaukums',
        'update' => 'Atjaunināt',
        'save' => 'Saglabāt',
        'cancel' => 'Atcelt',
        'export_csv' => 'Eksportēt CSV',
    ],

    'updating' => '… Atjaunina',

    'empty' => [
        'heading' => 'Šai atlasei nav ko rādīt',
        'body' => 'Paplašiniet datumu periodu vai noņemiet kādu filtru.',
    ],

    'total_prefix' => 'Kopā',
    'total' => 'Kopā',
    'vs_previous' => 'pret iepriekšējo periodu',
    'view_transactions' => 'Skatīt darījumus',

    'fx_excluded' => ':count kontu nav konvertēti — nav pieejams kurss|:count konts nav konvertēts — nav pieejams kurss|:count konti nav konvertēti — nav pieejams kurss',

    'group_header' => [
        'category' => 'Kategorija',
        'counterparty' => 'Darījuma partneris',
        'account' => 'Konts',
        'month' => 'Mēnesis',
        'default' => 'Grupa',
    ],

    'chart' => [
        'bar_title' => 'Noklikšķiniet uz stabiņa, lai redzētu tā darījumus',
        'line_title' => 'Noklikšķiniet uz punkta, lai redzētu tā darījumus',
        'donut_title' => 'Noklikšķiniet uz segmenta, lai redzētu tā darījumus',
    ],

    'flash' => [
        'saved' => 'Atskaite saglabāta.',
        'updated' => 'Atskaite atjaunināta.',
    ],

    'filter' => [
        'account' => 'Konts',
        'account_count' => ':count kontu|:count konts|:count konti',
        'remove_account' => 'Noņemt konta filtru',
        'account_dialog' => 'Konta filtrs',

        'category' => 'Kategorija',
        'category_count' => ':count kategoriju|:count kategorija|:count kategorijas',
        'remove_category' => 'Noņemt kategorijas filtru',
        'category_dialog' => 'Kategorijas filtrs',

        'counterparty' => 'Darījuma partneris',
        'counterparty_count' => ':count darījuma partneru|:count darījuma partneris|:count darījuma partneri',
        'remove_counterparty' => 'Noņemt darījuma partnera filtru',
        'counterparty_dialog' => 'Darījuma partnera filtrs',

        'amount' => 'Summa',
        'remove_amount' => 'Noņemt summas filtru',
        'amount_dialog' => 'Summas filtrs',
        'dir_both' => 'Abi',
        'dir_in' => 'Ienākošie',
        'dir_out' => 'Izejošie',
        'min' => 'Min.',
        'max' => 'Maks.',
        'min_aria' => 'Minimālā summa',
        'max_aria' => 'Maksimālā summa',
    ],

    'other_movement' => 'Maksas un korekcijas (nav ieskaitītas)',
];
