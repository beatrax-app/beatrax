<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Ikke kategoriseret',
    'no_counterparty' => 'Ingen modpart',
    'unavailable_counterparty' => 'Modpart findes ikke på denne enhed',
    'title' => 'Rapporter',
    'page_title' => 'Rapporter · Beatrax',
    'subtitle' => 'Sammensæt en rapport ud fra dine transaktioner.',
    'controls_aria' => 'Rapportindstillinger',
    'result_aria' => 'Rapportresultat',
    'dismiss' => 'Luk',

    'metric' => [
        'heading' => 'Nøgletal',
        'spend' => 'Udgifter',
        'income' => 'Indtægter',
        'net' => 'Netto',
        'net_worth' => 'Nettoformue',
        'fallback' => 'Beløb',
    ],

    'group_by' => 'Gruppér efter',

    'dimension' => [
        'category' => 'Kategori',
        'time_bucket' => 'Tidsinterval',
        'counterparty' => 'Modpart',
        'account' => 'Konto',
    ],

    'period' => [
        'heading' => 'Periode',
        'this_month' => 'Denne måned',
        'last_3_months' => 'Sidste 3 måneder',
        'last_6_months' => 'Sidste 6 måneder',
        'last_12_months' => 'Sidste 12 måneder',
        'ytd' => 'År til dato',
        'this_year' => 'I år',
        'custom' => 'Tilpasset interval',
        'from' => 'Fra',
        'to' => 'Til',
        'error' => [
            'incomplete' => 'Vælg både en start- og en slutdato.',
            'malformed' => 'Brug en gyldig dato i formatet ÅÅÅÅ-MM-DD.',
            'inverted' => 'Slutdatoen ligger før startdatoen.',
        ],
    ],

    'currency' => [
        'heading' => 'Valuta',
        'aria' => 'Valutatilstand',
        'base' => 'Basis',
        'original' => 'Oprindelig',
    ],

    'granularity' => [
        'heading' => 'Detaljeringsgrad',
        'aria' => 'Tidsopløsning',
        'monthly' => 'Månedligt',
        'weekly' => 'Ugentligt',
    ],

    'filters' => [
        'heading' => 'Filtre',
        'net_worth_note' => 'Nettoformue er en saldo: kun kontofilteret gælder.',
    ],

    'compare' => 'Sammenlign med forrige periode',

    'viz' => [
        'heading' => 'Visualisering',
        'table' => 'Tabel',
        'bar' => 'Søjle',
        'line' => 'Linje',
        'donut' => 'Ring',
    ],

    'actions' => [
        'update_report' => 'Opdatér rapporten',
        'save_report' => 'Gem rapporten',
        'report_name' => 'Rapportnavn',
        'update' => 'Opdatér',
        'save' => 'Gem',
        'cancel' => 'Annullér',
        'export_csv' => 'Eksportér CSV',
    ],

    'updating' => '… Opdaterer',

    'empty' => [
        'heading' => 'Intet at vise for dette valg',
        'body' => 'Prøv at udvide datointervallet eller fjerne et filter.',
    ],

    'total_prefix' => 'I alt',
    'total' => 'I alt',
    'vs_previous' => 'mod forrige periode',
    'view_transactions' => 'Vis transaktioner',

    'fx_excluded' => ':count konto er ikke omregnet — ingen kurs tilgængelig|:count konti er ikke omregnet — ingen kurs tilgængelig',

    'group_header' => [
        'category' => 'Kategori',
        'counterparty' => 'Modpart',
        'account' => 'Konto',
        'month' => 'Måned',
        'default' => 'Gruppe',
    ],

    'chart' => [
        'other_currencies' => 'Diagram i :currency — :list vises ikke',
        'undrawn' => 'Ikke i ringen — :amount går den anden vej',
        'bar_title' => 'Klik på en søjle for at se dens transaktioner',
        'line_title' => 'Klik på et punkt for at se dets transaktioner',
        'donut_title' => 'Klik på et segment for at se dets transaktioner',
    ],

    'flash' => [
        'saved' => 'Rapporten er gemt.',
        'updated' => 'Rapporten er opdateret.',
    ],

    'filter' => [
        'account' => 'Konto',
        'account_count' => ':count konto|:count konti',
        'remove_account' => 'Fjern kontofilteret',
        'account_dialog' => 'Kontofilter',

        'category' => 'Kategori',
        'category_count' => ':count kategori|:count kategorier',
        'remove_category' => 'Fjern kategorifilteret',
        'category_dialog' => 'Kategorifilter',

        'counterparty' => 'Modpart',
        'counterparty_count' => ':count modpart|:count modparter',
        'remove_counterparty' => 'Fjern modpartsfilteret',
        'counterparty_dialog' => 'Modpartsfilter',

        'amount' => 'Beløb',
        'remove_amount' => 'Fjern beløbsfilteret',
        'amount_dialog' => 'Beløbsfilter',
        'dir_both' => 'Begge',
        'dir_in' => 'Ind',
        'dir_out' => 'Ud',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Mindste beløb',
        'max_aria' => 'Største beløb',
    ],

    'other_movement' => 'Gebyrer og reguleringer (ikke medregnet)',
    'other_movement_with_refunds' => 'Gebyrer, refusioner og reguleringer (ikke medregnet)',
];
