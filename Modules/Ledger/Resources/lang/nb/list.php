<?php

declare(strict_types=1);

return [
    'page_title' => 'Transaksjoner',
    'heading' => 'Transaksjoner',

    'subtitle_searching' => 'Søker i hele historikken',
    'subtitle_full' => 'Hele historikken.',
    'subtitle_recent' => 'Nylige transaksjoner (siste 90 dager).',

    'currency_aria' => 'Vist beløp',
    'currency_eur' => 'Oppgjort beløp',
    'currency_original' => 'Opprinnelig beløp',

    'show_recent' => 'Vis bare de nyeste',
    'show_full' => 'Vis hele historikken',

    'empty_period' => 'Ingenting her for denne perioden.',

    'empty_recent_has_older' => 'Ingenting de siste 90 dagene. De eldre posteringene dine er fortsatt her.',

    'empty_history' => 'Ingen posteringer ennå.',
    'loading_more' => 'Laster flere transaksjoner',
    'load_more' => 'Last inn flere',

    'split_badge' => 'Oppdelt · :count',
    'split_expand_aria' => 'Oppdelt på :count kategori — utvid for å se|Oppdelt på :count kategorier — utvid for å se',

    'chain_badge' => 'kjede',
    'chain_title' => 'Del av en kjede — åpne denne raden for å se',

    'table' => [
        'date' => 'Dato',
        'counterparty' => 'Motpart',
        'category' => 'Kategori',
        'tax' => 'Skatt',
        'status' => 'Status',
        'amount' => 'Beløp',
    ],

    'search' => [
        'placeholder' => 'Søk etter forhandler, beskrivelse, notater…',
        'placeholder_short' => 'Søk i transaksjoner…',
        'aria' => 'Søk i transaksjoner',
        'clear_all' => 'Tøm alt',
        'filters' => 'Filtre',
        'open_filters_aria' => 'Åpne filtre',
        'apply' => 'Bruk',
        'clear' => 'Tøm',

        'count' => ':count transaksjon|:count transaksjoner',
        'matching_suffix' => 'som treffer filtrene',
        'flow' => ':out ut / :in inn',
    ],

    'no_results' => [
        'heading' => 'Ingen treff',
        'remove_prompt' => 'Prøv å fjerne et filter som kan snevre inn resultatet:',
        'no_match_query' => 'Ingen transaksjoner treffer “:query” i hele historikken.',
        'no_match_filters' => 'Ingen transaksjoner treffer filtrene som er valgt.',
        'did_you_mean' => 'Mente du:',
        'account_fallback' => 'Konto :id',
        'category_fallback' => 'Kategori :id',
    ],

    'filter' => [
        'date' => 'Dato',
        'account' => 'Konto',
        'amount' => 'Beløp',
        'category' => 'Kategori',
        'date_range' => 'Datointervall',
        'from' => 'Fra',
        'to' => 'Til',
        'custom_range' => 'Egendefinert intervall ×',
        'after' => 'Etter :date ×',
        'before' => 'Før :date ×',
        'dir_both' => 'Begge',
        'dir_in' => 'Inn',
        'dir_out' => 'Ut',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Minste beløp',
        'max_aria' => 'Største beløp',
        'after_aria' => 'Etter dato',
        'before_aria' => 'Før dato',
        'acct' => ':count konto|:count kontoer',
        'cat' => ':count kategori|:count kategorier',
        'date_dialog' => 'Datofilter',
        'account_dialog' => 'Kontofilter',
        'amount_dialog' => 'Beløpsfilter',
        'category_dialog' => 'Kategorifilter',
        'remove_date_aria' => 'Fjern datofilteret',
        'remove_account_aria' => 'Fjern kontofilteret',
        'remove_amount_aria' => 'Fjern beløpsfilteret',
        'remove_category_aria' => 'Fjern kategorifilteret',

        'remove_named_aria' => 'Fjern :name-filteret',
    ],

    'date_preset' => [
        'this_month' => 'Denne måneden',
        'last_month' => 'Forrige måned',
        'this_year' => 'I år',
        'last_year' => 'I fjor',
    ],
];
