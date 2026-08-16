<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakcije',
    'heading' => 'Transakcije',

    'subtitle_searching' => 'Pretraživanje cijele povijesti',
    'subtitle_full' => 'Cijela povijest.',
    'subtitle_recent' => 'Nedavne transakcije (zadnjih 90 dana).',

    'currency_aria' => 'Prikaz valute',
    'currency_eur' => 'Samo EUR',
    'currency_original' => 'Izvorna valuta',

    'show_recent' => 'Prikaži samo nedavne',
    'show_full' => 'Prikaži cijelu povijest',

    'empty_period' => 'Nema ničega za ovo razdoblje.',

    'loading_more' => 'Učitavanje još transakcija',
    'load_more' => 'Učitaj još',

    'split_badge' => 'Podjela · :count',
    'split_expand_aria' => 'Podijeljeno na :count kategorija — proširi za prikaz',

    'chain_badge' => 'lanac',
    'chain_title' => 'Dio lanca — otvori ovaj redak za prikaz',

    'table' => [
        'date' => 'Datum',
        'counterparty' => 'Protustranka',
        'category' => 'Kategorija',
        'tax' => 'Porez',
        'status' => 'Status',
        'amount' => 'Iznos',
    ],

    'search' => [
        'placeholder' => 'Pretraži trgovca, opis, bilješke…',
        'placeholder_short' => 'Pretraži transakcije…',
        'aria' => 'Pretraži transakcije',
        'clear_all' => 'Očisti sve',
        'filters' => 'Filtri',
        'open_filters_aria' => 'Otvori filtre',
        'apply' => 'Primijeni',
        'clear' => 'Očisti',

        'count' => ':count transakcija|:count transakcije|:count transakcija',
        'matching_suffix' => 'prema aktivnim filtrima',
        'flow' => ':out odljev / :in priljev',
    ],

    'no_results' => [
        'heading' => 'Nema podudaranja',
        'remove_prompt' => 'Pokušaj ukloniti filtar koji možda sužava rezultate:',
        'no_match_query' => 'Nijedna transakcija u cijeloj povijesti ne odgovara upitu „:query”.',
        'no_match_filters' => 'Nijedna transakcija ne odgovara primijenjenim filtrima.',
        'did_you_mean' => 'Jesi li mislio:',
        'account_fallback' => 'Račun :id',
        'category_fallback' => 'Kategorija :id',
    ],

    'filter' => [
        'date' => 'Datum',
        'account' => 'Račun',
        'amount' => 'Iznos',
        'category' => 'Kategorija',
        'date_range' => 'Raspon datuma',
        'from' => 'Od',
        'to' => 'Do',
        'custom_range' => 'Prilagođeni raspon ×',
        'after' => 'Nakon :date ×',
        'before' => 'Prije :date ×',
        'dir_both' => 'Oboje',
        'dir_in' => 'Priljev',
        'dir_out' => 'Odljev',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Najmanji iznos',
        'max_aria' => 'Najveći iznos',
        'after_aria' => 'Nakon datuma',
        'before_aria' => 'Prije datuma',
        'acct' => ':count račun|:count računa|:count računa',
        'cat' => ':count kategorija|:count kategorije|:count kategorija',
        'date_dialog' => 'Filtar datuma',
        'account_dialog' => 'Filtar računa',
        'amount_dialog' => 'Filtar iznosa',
        'category_dialog' => 'Filtar kategorije',
        'remove_date_aria' => 'Ukloni filtar datuma',
        'remove_account_aria' => 'Ukloni filtar računa',
        'remove_amount_aria' => 'Ukloni filtar iznosa',
        'remove_category_aria' => 'Ukloni filtar kategorije',

        'remove_named_aria' => 'Ukloni filtar :name',
    ],

    'date_preset' => [
        'this_month' => 'Ovaj mjesec',
        'last_month' => 'Prošli mjesec',
        'this_year' => 'Ova godina',
        'last_year' => 'Prošla godina',
    ],
];
