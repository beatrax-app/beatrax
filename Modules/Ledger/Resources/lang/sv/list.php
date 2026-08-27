<?php

declare(strict_types=1);

return [
    'page_title' => 'Transaktioner',
    'heading' => 'Transaktioner',

    'subtitle_searching' => 'Söker i hela historiken',
    'subtitle_full' => 'Hela historiken.',
    'subtitle_recent' => 'Senaste transaktionerna (senaste 90 dagarna).',

    'currency_aria' => 'Valutavy',
    'currency_eur' => 'Endast :code',
    'currency_original' => 'Ursprunglig valuta',

    'show_recent' => 'Visa endast de senaste',
    'show_full' => 'Visa hela historiken',

    'empty_period' => 'Inget här för den här perioden.',


    'empty_recent_has_older' => 'Inget de senaste 90 dagarna. Dina äldre transaktioner finns kvar.',

    'empty_history' => 'Inga transaktioner ännu.',
    'loading_more' => 'Laddar fler transaktioner',
    'load_more' => 'Ladda fler',

    'split_badge' => 'Uppdelad · :count',
    'split_expand_aria' => 'Uppdelad på :count kategori — expandera för att visa|Uppdelad på :count kategorier — expandera för att visa',

    'chain_badge' => 'kedja',
    'chain_title' => 'Del av en kedja — öppna raden för att visa',

    'table' => [
        'date' => 'Datum',
        'counterparty' => 'Motpart',
        'category' => 'Kategori',
        'tax' => 'Skatt',
        'status' => 'Status',
        'amount' => 'Belopp',
    ],

    'search' => [
        'placeholder' => 'Sök handlare, beskrivning, anteckningar…',
        'placeholder_short' => 'Sök transaktioner…',
        'aria' => 'Sök transaktioner',
        'clear_all' => 'Rensa allt',
        'filters' => 'Filter',
        'open_filters_aria' => 'Öppna filter',
        'apply' => 'Tillämpa',
        'clear' => 'Rensa',

        'count' => ':count transaktion|:count transaktioner',
        'matching_suffix' => 'som matchar filtren',
        'flow' => ':out ut / :in in',
    ],

    'no_results' => [
        'heading' => 'Inget matchar',
        'remove_prompt' => 'Prova att ta bort ett filter som kan begränsa resultatet:',
        'no_match_query' => 'Inga transaktioner matchar “:query” i hela historiken.',
        'no_match_filters' => 'Inga transaktioner matchar de valda filtren.',
        'did_you_mean' => 'Menade du:',
        'account_fallback' => 'Konto :id',
        'category_fallback' => 'Kategori :id',
    ],

    'filter' => [
        'date' => 'Datum',
        'account' => 'Konto',
        'amount' => 'Belopp',
        'category' => 'Kategori',
        'date_range' => 'Datumintervall',
        'from' => 'Från',
        'to' => 'Till',
        'custom_range' => 'Anpassat intervall ×',
        'after' => 'Efter :date ×',
        'before' => 'Före :date ×',
        'dir_both' => 'Båda',
        'dir_in' => 'In',
        'dir_out' => 'Ut',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minsta belopp',
        'max_aria' => 'Största belopp',
        'after_aria' => 'Efter datum',
        'before_aria' => 'Före datum',
        'acct' => ':count konto|:count konton',
        'cat' => ':count kategori|:count kategorier',
        'date_dialog' => 'Datumfilter',
        'account_dialog' => 'Kontofilter',
        'amount_dialog' => 'Beloppsfilter',
        'category_dialog' => 'Kategorifilter',
        'remove_date_aria' => 'Ta bort datumfiltret',
        'remove_account_aria' => 'Ta bort kontofiltret',
        'remove_amount_aria' => 'Ta bort beloppsfiltret',
        'remove_category_aria' => 'Ta bort kategorifiltret',

        'remove_named_aria' => 'Ta bort filtret :name',
    ],

    'date_preset' => [
        'this_month' => 'Den här månaden',
        'last_month' => 'Förra månaden',
        'this_year' => 'I år',
        'last_year' => 'Förra året',
    ],
];
