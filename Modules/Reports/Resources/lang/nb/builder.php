<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Ikke kategorisert',
    'no_counterparty' => 'Ingen motpart',
    'unavailable_counterparty' => 'Motparten finnes ikke på denne enheten',
    'title' => 'Rapporter',
    'page_title' => 'Rapporter · Beatrax',
    'subtitle' => 'Sett sammen en rapport ut fra transaksjonene dine.',
    'controls_aria' => 'Rapportinnstillinger',
    'result_aria' => 'Rapportresultat',
    'dismiss' => 'Lukk',

    'metric' => [
        'heading' => 'Nøkkeltall',
        'spend' => 'Utgifter',
        'income' => 'Inntekter',
        'net' => 'Netto',
        'net_worth' => 'Nettoformue',
        'fallback' => 'Beløp',
    ],

    'group_by' => 'Grupper etter',

    'dimension' => [
        'category' => 'Kategori',
        'time_bucket' => 'Tidsintervall',
        'counterparty' => 'Motpart',
        'account' => 'Konto',
    ],

    'period' => [
        'heading' => 'Periode',
        'this_month' => 'Denne måneden',
        'last_3_months' => 'Siste 3 måneder',
        'last_6_months' => 'Siste 6 måneder',
        'last_12_months' => 'Siste 12 måneder',
        'ytd' => 'Hittil i år',
        'this_year' => 'I år',
        'custom' => 'Egendefinert intervall',
        'from' => 'Fra',
        'to' => 'Til',
        'error' => [
            'incomplete' => 'Velg både en start- og en sluttdato.',
            'malformed' => 'Bruk en gyldig dato på formatet ÅÅÅÅ-MM-DD.',
            'inverted' => 'Sluttdatoen er før startdatoen.',
        ],
    ],

    'currency' => [
        'heading' => 'Valuta',
        'aria' => 'Valutamodus',
        'base' => 'Basis',
        'original' => 'Opprinnelig',
    ],

    'granularity' => [
        'heading' => 'Detaljnivå',
        'aria' => 'Tidsoppløsning',
        'monthly' => 'Månedlig',
        'weekly' => 'Ukentlig',
    ],

    'filters' => [
        'heading' => 'Filtre',
        'net_worth_note' => 'Nettoformue er en saldo: bare kontofilteret gjelder.',
    ],

    'compare' => 'Sammenlign med forrige periode',

    'viz' => [
        'heading' => 'Visualisering',
        'table' => 'Tabell',
        'bar' => 'Søyle',
        'line' => 'Linje',
        'donut' => 'Ring',
    ],

    'actions' => [
        'update_report' => 'Oppdater rapporten',
        'save_report' => 'Lagre rapporten',
        'report_name' => 'Rapportnavn',
        'update' => 'Oppdater',
        'save' => 'Lagre',
        'cancel' => 'Avbryt',
        'export_csv' => 'Eksporter CSV',
    ],

    'updating' => '… Oppdaterer',

    'empty' => [
        'heading' => 'Ingenting å vise for dette utvalget',
        'body' => 'Prøv å utvide datointervallet eller fjerne et filter.',
    ],

    'total_prefix' => 'Totalt',
    'total' => 'Totalt',
    'vs_previous' => 'mot forrige periode',
    'view_transactions' => 'Vis transaksjoner',

    'fx_excluded' => ':count konto er ikke omregnet — ingen kurs tilgjengelig|:count kontoer er ikke omregnet — ingen kurs tilgjengelig',

    'group_header' => [
        'category' => 'Kategori',
        'counterparty' => 'Motpart',
        'account' => 'Konto',
        'month' => 'Måned',
        'default' => 'Gruppe',
    ],

    'chart' => [
        'other_currencies' => 'Diagram i :currency — :list vises ikke',
        'undrawn' => 'Ikke i ringen — :amount går motsatt vei',
        'bar_title' => 'Klikk på en søyle for å se transaksjonene bak den',
        'line_title' => 'Klikk på et punkt for å se transaksjonene bak det',
        'donut_title' => 'Klikk på et segment for å se transaksjonene bak det',
    ],

    'flash' => [
        'saved' => 'Rapporten er lagret.',
        'updated' => 'Rapporten er oppdatert.',
    ],

    'filter' => [
        'account' => 'Konto',
        'account_count' => ':count konto|:count kontoer',
        'remove_account' => 'Fjern kontofilteret',
        'account_dialog' => 'Kontofilter',

        'category' => 'Kategori',
        'category_count' => ':count kategori|:count kategorier',
        'remove_category' => 'Fjern kategorifilteret',
        'category_dialog' => 'Kategorifilter',

        'counterparty' => 'Motpart',
        'counterparty_count' => ':count motpart|:count motparter',
        'remove_counterparty' => 'Fjern motpartsfilteret',
        'counterparty_dialog' => 'Motpartsfilter',

        'amount' => 'Beløp',
        'remove_amount' => 'Fjern beløpsfilteret',
        'amount_dialog' => 'Beløpsfilter',
        'dir_both' => 'Begge',
        'dir_in' => 'Inn',
        'dir_out' => 'Ut',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Minste beløp',
        'max_aria' => 'Største beløp',
    ],

    'other_movement' => 'Gebyrer og justeringer (ikke medregnet)',
    'other_movement_with_refunds' => 'Gebyrer, refusjoner og justeringer (ikke medregnet)',
];
