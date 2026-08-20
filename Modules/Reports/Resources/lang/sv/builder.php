<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Okategoriserat',
    'title' => 'Rapporter',
    'page_title' => 'Rapporter · Beatrax',
    'subtitle' => 'Sätt ihop en rapport utifrån dina transaktioner.',
    'controls_aria' => 'Rapportinställningar',
    'result_aria' => 'Rapportresultat',
    'dismiss' => 'Stäng',

    'metric' => [
        'heading' => 'Nyckeltal',
        'spend' => 'Utgifter',
        'income' => 'Inkomster',
        'net' => 'Netto',
        'net_worth' => 'Nettoförmögenhet',
        'fallback' => 'Belopp',
    ],

    'group_by' => 'Gruppera efter',

    'dimension' => [
        'category' => 'Kategori',
        'time_bucket' => 'Tidsintervall',
        'counterparty' => 'Motpart',
        'account' => 'Konto',
    ],

    'period' => [
        'heading' => 'Period',
        'this_month' => 'Den här månaden',
        'last_3_months' => 'Senaste 3 månaderna',
        'last_6_months' => 'Senaste 6 månaderna',
        'last_12_months' => 'Senaste 12 månaderna',
        'ytd' => 'Hittills i år',
        'this_year' => 'I år',
        'custom' => 'Anpassat intervall',
        'from' => 'Från',
        'to' => 'Till',
    ],

    'currency' => [
        'heading' => 'Valuta',
        'aria' => 'Valutaläge',
        'base' => 'Bas',
        'original' => 'Ursprunglig',
    ],

    'granularity' => [
        'heading' => 'Detaljnivå',
        'aria' => 'Tidsupplösning',
        'monthly' => 'Månadsvis',
        'weekly' => 'Veckovis',
    ],

    'filters' => [
        'heading' => 'Filter',
    ],

    'compare' => 'Jämför med föregående period',

    'viz' => [
        'heading' => 'Visualisering',
        'table' => 'Tabell',
        'bar' => 'Stapel',
        'line' => 'Linje',
        'donut' => 'Ring',
    ],

    'actions' => [
        'update_report' => 'Uppdatera rapporten',
        'save_report' => 'Spara rapporten',
        'report_name' => 'Rapportnamn',
        'update' => 'Uppdatera',
        'save' => 'Spara',
        'cancel' => 'Avbryt',
        'export_csv' => 'Exportera CSV',
    ],

    'updating' => '… Uppdaterar',

    'empty' => [
        'heading' => 'Inget att visa för det här urvalet',
        'body' => 'Prova att bredda datumintervallet eller ta bort ett filter.',
    ],

    'total_prefix' => 'Totalt',
    'total' => 'Totalt',
    'vs_previous' => 'mot föregående period',
    'view_transactions' => 'Visa transaktioner',

    'fx_excluded' => ':count konto har inte räknats om — ingen kurs tillgänglig|:count konton har inte räknats om — ingen kurs tillgänglig',

    'group_header' => [
        'category' => 'Kategori',
        'counterparty' => 'Motpart',
        'account' => 'Konto',
        'month' => 'Månad',
        'default' => 'Grupp',
    ],

    'chart' => [
        'bar_title' => 'Klicka på en stapel för att visa dess transaktioner',
        'line_title' => 'Klicka på en punkt för att visa dess transaktioner',
        'donut_title' => 'Klicka på ett segment för att visa dess transaktioner',
    ],

    'flash' => [
        'saved' => 'Rapporten sparad.',
        'updated' => 'Rapporten uppdaterad.',
    ],

    'filter' => [
        'account' => 'Konto',
        'account_count' => ':count konto|:count konton',
        'remove_account' => 'Ta bort kontofiltret',
        'account_dialog' => 'Kontofilter',

        'category' => 'Kategori',
        'category_count' => ':count kategori|:count kategorier',
        'remove_category' => 'Ta bort kategorifiltret',
        'category_dialog' => 'Kategorifilter',

        'counterparty' => 'Motpart',
        'counterparty_count' => ':count motpart|:count motparter',
        'remove_counterparty' => 'Ta bort motpartsfiltret',
        'counterparty_dialog' => 'Motpartsfilter',

        'amount' => 'Belopp',
        'remove_amount' => 'Ta bort beloppsfiltret',
        'amount_dialog' => 'Beloppsfilter',
        'dir_both' => 'Båda',
        'dir_in' => 'In',
        'dir_out' => 'Ut',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minsta belopp',
        'max_aria' => 'Största belopp',
    ],

    'other_movement' => 'Avgifter och justeringar (ej medräknade)',
];
