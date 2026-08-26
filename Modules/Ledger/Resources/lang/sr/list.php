<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakcije',
    'heading' => 'Transakcije',

    'subtitle_searching' => 'Pretraga cele istorije',
    'subtitle_full' => 'Cela istorija.',
    'subtitle_recent' => 'Nedavne transakcije (poslednjih 90 dana).',

    'currency_aria' => 'Prikaz valute',
    'currency_eur' => 'Samo :code',
    'currency_original' => 'Izvorna valuta',

    'show_recent' => 'Prikaži samo nedavne',
    'show_full' => 'Prikaži celu istoriju',

    'empty_period' => 'Nema ničega za ovaj period.',

    'loading_more' => 'Učitavanje još transakcija',
    'load_more' => 'Učitaj još',

    'split_badge' => 'Podela · :count',
    'split_expand_aria' => 'Podeljeno na :count kategoriju — proširi za prikaz|Podeljeno na :count kategorije — proširi za prikaz|Podeljeno na :count kategorija — proširi za prikaz',

    'chain_badge' => 'lanac',
    'chain_title' => 'Deo lanca — otvori ovaj red za prikaz',

    'table' => [
        'date' => 'Datum',
        'counterparty' => 'Druga strana',
        'category' => 'Kategorija',
        'tax' => 'Porez',
        'status' => 'Status',
        'amount' => 'Iznos',
    ],

    'search' => [
        'placeholder' => 'Pretraži trgovca, opis, beleške…',
        'placeholder_short' => 'Pretraži transakcije…',
        'aria' => 'Pretraži transakcije',
        'clear_all' => 'Očisti sve',
        'filters' => 'Filteri',
        'open_filters_aria' => 'Otvori filtere',
        'apply' => 'Primeni',
        'clear' => 'Očisti',

        'count' => ':count transakcija|:count transakcije|:count transakcija',
        'matching_suffix' => 'prema aktivnim filterima',
        'flow' => ':out odliv / :in priliv',
    ],

    'no_results' => [
        'heading' => 'Nema poklapanja',
        'remove_prompt' => 'Probaj da ukloniš filter koji možda sužava rezultate:',
        'no_match_query' => 'Nijedna transakcija u celoj istoriji ne odgovara upitu „:query”.',
        'no_match_filters' => 'Nijedna transakcija ne odgovara primenjenim filterima.',
        'did_you_mean' => 'Da li si mislio:',
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
        'after' => 'Posle :date ×',
        'before' => 'Pre :date ×',
        'dir_both' => 'Oboje',
        'dir_in' => 'Priliv',
        'dir_out' => 'Odliv',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Najmanji iznos',
        'max_aria' => 'Najveći iznos',
        'after_aria' => 'Posle datuma',
        'before_aria' => 'Pre datuma',
        'acct' => ':count račun|:count računa|:count računa',
        'cat' => ':count kategorija|:count kategorije|:count kategorija',
        'date_dialog' => 'Filter datuma',
        'account_dialog' => 'Filter računa',
        'amount_dialog' => 'Filter iznosa',
        'category_dialog' => 'Filter kategorije',
        'remove_date_aria' => 'Ukloni filter datuma',
        'remove_account_aria' => 'Ukloni filter računa',
        'remove_amount_aria' => 'Ukloni filter iznosa',
        'remove_category_aria' => 'Ukloni filter kategorije',

        'remove_named_aria' => 'Ukloni filter :name',
    ],

    'date_preset' => [
        'this_month' => 'Ovaj mesec',
        'last_month' => 'Prošli mesec',
        'this_year' => 'Ova godina',
        'last_year' => 'Prošla godina',
    ],
];
