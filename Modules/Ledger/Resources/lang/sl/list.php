<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakcije',
    'heading' => 'Transakcije',

    'subtitle_searching' => 'Iskanje po celotni zgodovini',
    'subtitle_full' => 'Celotna zgodovina.',
    'subtitle_recent' => 'Nedavne transakcije (zadnjih 90 dni).',

    'currency_aria' => 'Prikaz valute',
    'currency_eur' => 'Samo :code',
    'currency_original' => 'Izvirna valuta',

    'show_recent' => 'Prikaži samo nedavne',
    'show_full' => 'Prikaži celotno zgodovino',

    'empty_period' => 'Za to obdobje ni ničesar.',

    'loading_more' => 'Nalaganje več transakcij',
    'load_more' => 'Naloži več',

    'split_badge' => 'Razdelitev · :count',
    'split_expand_aria' => 'Razdeljeno na :count kategorijo — razširi za prikaz|Razdeljeno na :count kategoriji — razširi za prikaz|Razdeljeno na :count kategorije — razširi za prikaz|Razdeljeno na :count kategorij — razširi za prikaz',

    'chain_badge' => 'veriga',
    'chain_title' => 'Del verige — odpri to vrstico za prikaz',

    'table' => [
        'date' => 'Datum',
        'counterparty' => 'Nasprotna stranka',
        'category' => 'Kategorija',
        'tax' => 'Davek',
        'status' => 'Stanje',
        'amount' => 'Znesek',
    ],

    'search' => [
        'placeholder' => 'Išči trgovca, opis, opombe…',
        'placeholder_short' => 'Išči transakcije…',
        'aria' => 'Išči transakcije',
        'clear_all' => 'Počisti vse',
        'filters' => 'Filtri',
        'open_filters_aria' => 'Odpri filtre',
        'apply' => 'Uporabi',
        'clear' => 'Počisti',

        'count' => ':count transakcija|:count transakciji|:count transakcije|:count transakcij',
        'matching_suffix' => 'glede na aktivne filtre',
        'flow' => ':out odliv / :in priliv',
    ],

    'no_results' => [
        'heading' => 'Ni zadetkov',
        'remove_prompt' => 'Poskusi odstraniti filter, ki morda oži rezultate:',
        'no_match_query' => 'Nobena transakcija v celotni zgodovini se ne ujema z „:query“.',
        'no_match_filters' => 'Nobena transakcija se ne ujema z uporabljenimi filtri.',
        'did_you_mean' => 'Si mislil:',
        'account_fallback' => 'Račun :id',
        'category_fallback' => 'Kategorija :id',
    ],

    'filter' => [
        'date' => 'Datum',
        'account' => 'Račun',
        'amount' => 'Znesek',
        'category' => 'Kategorija',
        'date_range' => 'Razpon datumov',
        'from' => 'Od',
        'to' => 'Do',
        'custom_range' => 'Prilagojen razpon ×',
        'after' => 'Po :date ×',
        'before' => 'Pred :date ×',
        'dir_both' => 'Oboje',
        'dir_in' => 'Priliv',
        'dir_out' => 'Odliv',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Najmanjši znesek',
        'max_aria' => 'Največji znesek',
        'after_aria' => 'Po datumu',
        'before_aria' => 'Pred datumom',
        'acct' => ':count račun|:count računa|:count računi|:count računov',
        'cat' => ':count kategorija|:count kategoriji|:count kategorije|:count kategorij',
        'date_dialog' => 'Filter datuma',
        'account_dialog' => 'Filter računa',
        'amount_dialog' => 'Filter zneska',
        'category_dialog' => 'Filter kategorije',
        'remove_date_aria' => 'Odstrani filter datuma',
        'remove_account_aria' => 'Odstrani filter računa',
        'remove_amount_aria' => 'Odstrani filter zneska',
        'remove_category_aria' => 'Odstrani filter kategorije',

        'remove_named_aria' => 'Odstrani filter :name',
    ],

    'date_preset' => [
        'this_month' => 'Ta mesec',
        'last_month' => 'Prejšnji mesec',
        'this_year' => 'To leto',
        'last_year' => 'Prejšnje leto',
    ],
];
