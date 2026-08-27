<?php

declare(strict_types=1);

return [
    'page_title' => 'Transaktioner',
    'heading' => 'Transaktioner',

    'subtitle_searching' => 'Søger i hele historikken',
    'subtitle_full' => 'Hele historikken.',
    'subtitle_recent' => 'Seneste transaktioner (sidste 90 dage).',

    'currency_aria' => 'Valutavisning',
    'currency_eur' => 'Kun :code',
    'currency_original' => 'Oprindelig valuta',

    'show_recent' => 'Vis kun de seneste',
    'show_full' => 'Vis hele historikken',

    'empty_period' => 'Intet her i denne periode.',


    'empty_recent_has_older' => 'Intet i de seneste 90 dage. Dine ældre posteringer er her stadig.',

    'empty_history' => 'Ingen posteringer endnu.',
    'loading_more' => 'Indlæser flere transaktioner',
    'load_more' => 'Indlæs flere',

    'split_badge' => 'Opdelt · :count',
    'split_expand_aria' => 'Opdelt på :count kategori — fold ud for at se|Opdelt på :count kategorier — fold ud for at se',

    'chain_badge' => 'kæde',
    'chain_title' => 'Del af en kæde — åbn denne række for at se',

    'table' => [
        'date' => 'Dato',
        'counterparty' => 'Modpart',
        'category' => 'Kategori',
        'tax' => 'Skat',
        'status' => 'Status',
        'amount' => 'Beløb',
    ],

    'search' => [
        'placeholder' => 'Søg forhandler, beskrivelse, noter…',
        'placeholder_short' => 'Søg transaktioner…',
        'aria' => 'Søg transaktioner',
        'clear_all' => 'Ryd alt',
        'filters' => 'Filtre',
        'open_filters_aria' => 'Åbn filtre',
        'apply' => 'Anvend',
        'clear' => 'Ryd',

        'count' => ':count transaktion|:count transaktioner',
        'matching_suffix' => 'der matcher filtrene',
        'flow' => ':out ud / :in ind',
    ],

    'no_results' => [
        'heading' => 'Ingen match',
        'remove_prompt' => 'Prøv at fjerne et filter, der kan indsnævre resultatet:',
        'no_match_query' => 'Ingen transaktioner matcher “:query” i hele historikken.',
        'no_match_filters' => 'Ingen transaktioner matcher de valgte filtre.',
        'did_you_mean' => 'Mente du:',
        'account_fallback' => 'Konto :id',
        'category_fallback' => 'Kategori :id',
    ],

    'filter' => [
        'date' => 'Dato',
        'account' => 'Konto',
        'amount' => 'Beløb',
        'category' => 'Kategori',
        'date_range' => 'Datointerval',
        'from' => 'Fra',
        'to' => 'Til',
        'custom_range' => 'Tilpasset interval ×',
        'after' => 'Efter :date ×',
        'before' => 'Før :date ×',
        'dir_both' => 'Begge',
        'dir_in' => 'Ind',
        'dir_out' => 'Ud',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Mindste beløb',
        'max_aria' => 'Største beløb',
        'after_aria' => 'Efter dato',
        'before_aria' => 'Før dato',
        'acct' => ':count konto|:count konti',
        'cat' => ':count kategori|:count kategorier',
        'date_dialog' => 'Datofilter',
        'account_dialog' => 'Kontofilter',
        'amount_dialog' => 'Beløbsfilter',
        'category_dialog' => 'Kategorifilter',
        'remove_date_aria' => 'Fjern datofilteret',
        'remove_account_aria' => 'Fjern kontofilteret',
        'remove_amount_aria' => 'Fjern beløbsfilteret',
        'remove_category_aria' => 'Fjern kategorifilteret',

        'remove_named_aria' => 'Fjern :name-filteret',
    ],

    'date_preset' => [
        'this_month' => 'Denne måned',
        'last_month' => 'Sidste måned',
        'this_year' => 'I år',
        'last_year' => 'Sidste år',
    ],
];
