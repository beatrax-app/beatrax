<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Niet gecategoriseerd',
    'title' => 'Rapporten',
    'page_title' => 'Rapporten · Beatrax',
    'subtitle' => 'Stel een rapport samen uit je grootboek.',
    'controls_aria' => 'Rapportinstellingen',
    'result_aria' => 'Rapportresultaat',
    'dismiss' => 'Sluiten',

    'metric' => [
        'heading' => 'Maatstaf',
        'spend' => 'Uitgaven',
        'income' => 'Inkomsten',
        'net' => 'Netto',
        'net_worth' => 'Nettovermogen',
        'fallback' => 'Bedrag',
    ],

    'group_by' => 'Groeperen op',

    'dimension' => [
        'category' => 'Categorie',
        'time_bucket' => 'Tijdvak',
        'counterparty' => 'Tegenpartij',
        'account' => 'Rekening',
    ],

    'period' => [
        'heading' => 'Periode',
        'this_month' => 'Deze maand',
        'last_3_months' => 'Afgelopen 3 maanden',
        'last_6_months' => 'Afgelopen 6 maanden',
        'last_12_months' => 'Afgelopen 12 maanden',
        'ytd' => 'Jaar tot nu toe',
        'this_year' => 'Dit jaar',
        'custom' => 'Aangepast bereik',
        'from' => 'Van',
        'to' => 'Tot',
    ],

    'currency' => [
        'heading' => 'Valuta',
        'aria' => 'Valutamodus',
        'base' => 'Basis',
        'original' => 'Oorspronkelijk',
    ],

    'granularity' => [
        'heading' => 'Granulariteit',
        'aria' => 'Tijdsgranulariteit',
        'monthly' => 'Maandelijks',
        'weekly' => 'Wekelijks',
    ],

    'filters' => [
        'heading' => 'Filters',
    ],

    'compare' => 'Vergelijk met vorige periode',

    'viz' => [
        'heading' => 'Visualisatie',
        'table' => 'Tabel',
        'bar' => 'Staaf',
        'line' => 'Lijn',
        'donut' => 'Ring',
    ],

    'actions' => [
        'update_report' => 'Rapport bijwerken',
        'save_report' => 'Rapport opslaan',
        'report_name' => 'Rapportnaam',
        'update' => 'Bijwerken',
        'save' => 'Opslaan',
        'cancel' => 'Annuleren',
        'export_csv' => 'CSV exporteren',
    ],

    'updating' => '… Bezig met bijwerken',

    'empty' => [
        'heading' => 'Niets te tonen voor deze selectie',
        'body' => 'Probeer het datumbereik te verruimen of een filter te verwijderen.',
    ],

    'total_prefix' => 'Totaal',
    'total' => 'Totaal',
    'vs_previous' => 'vs. vorige periode',
    'view_transactions' => 'Transacties bekijken',

    'fx_excluded' => ':count rekening niet omgerekend — geen koers beschikbaar|:count rekeningen niet omgerekend — geen koers beschikbaar',

    'group_header' => [
        'category' => 'Categorie',
        'counterparty' => 'Tegenpartij',
        'account' => 'Rekening',
        'month' => 'Maand',
        'default' => 'Groep',
    ],

    'chart' => [
        'bar_title' => 'Klik op een staaf om de transacties te bekijken',
        'line_title' => 'Klik op een punt om de transacties te bekijken',
        'donut_title' => 'Klik op een segment om de transacties te bekijken',
    ],

    'flash' => [
        'saved' => 'Rapport opgeslagen.',
        'updated' => 'Rapport bijgewerkt.',
    ],

    'filter' => [
        'account' => 'Rekening',
        'account_count' => ':count rekening|:count rekeningen',
        'remove_account' => 'Rekeningfilter verwijderen',
        'account_dialog' => 'Rekeningfilter',

        'category' => 'Categorie',
        'category_count' => ':count categorie|:count categorieën',
        'remove_category' => 'Categoriefilter verwijderen',
        'category_dialog' => 'Categoriefilter',

        'counterparty' => 'Tegenpartij',
        'counterparty_count' => ':count tegenpartij|:count tegenpartijen',
        'remove_counterparty' => 'Tegenpartijfilter verwijderen',
        'counterparty_dialog' => 'Tegenpartijfilter',

        'amount' => 'Bedrag',
        'remove_amount' => 'Bedragfilter verwijderen',
        'amount_dialog' => 'Bedragfilter',
        'dir_both' => 'Beide',
        'dir_in' => 'In',
        'dir_out' => 'Uit',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minimumbedrag',
        'max_aria' => 'Maximumbedrag',
    ],
];
