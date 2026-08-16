<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakcie',
    'heading' => 'Transakcie',

    'subtitle_searching' => 'Prehľadáva sa celá história',
    'subtitle_full' => 'Celá história.',
    'subtitle_recent' => 'Nedávne transakcie (posledných 90 dní).',

    'currency_aria' => 'Zobrazenie meny',
    'currency_eur' => 'Iba EUR',
    'currency_original' => 'Pôvodná mena',

    'show_recent' => 'Zobraziť iba nedávne',
    'show_full' => 'Zobraziť celú históriu',

    'empty_period' => 'Za toto obdobie tu nič nie je.',

    'loading_more' => 'Načítavajú sa ďalšie transakcie',
    'load_more' => 'Načítať ďalšie',

    'split_badge' => 'Rozdelenie · :count',
    'split_expand_aria' => 'Rozdelené medzi kategórie (:count) — rozbaľ a pozri sa',

    'chain_badge' => 'reťazec',
    'chain_title' => 'Súčasť reťazca — otvor tento riadok a pozri sa',

    'table' => [
        'date' => 'Dátum',
        'counterparty' => 'Protistrana',
        'category' => 'Kategória',
        'tax' => 'Daň',
        'status' => 'Stav',
        'amount' => 'Suma',
    ],

    'search' => [
        'placeholder' => 'Hľadaj obchodníka, popis, poznámky…',
        'placeholder_short' => 'Hľadať transakcie…',
        'aria' => 'Hľadať transakcie',
        'clear_all' => 'Vymazať všetko',
        'filters' => 'Filtre',
        'open_filters_aria' => 'Otvoriť filtre',
        'apply' => 'Použiť',
        'clear' => 'Vymazať',

        'count' => ':count transakcia|:count transakcie|:count transakcií',
        'matching_suffix' => 'podľa aktívnych filtrov',
        'flow' => ':out výdavky / :in príjmy',
    ],

    'no_results' => [
        'heading' => 'Nič sa nezhoduje',
        'remove_prompt' => 'Skús odstrániť filter, ktorý môže výsledky zužovať:',
        'no_match_query' => 'V celej histórii sa žiadna transakcia nezhoduje s „:query“.',
        'no_match_filters' => 'Žiadna transakcia nezodpovedá použitým filtrom.',
        'did_you_mean' => 'Nemyslíš náhodou:',
        'account_fallback' => 'Účet :id',
        'category_fallback' => 'Kategória :id',
    ],

    'filter' => [
        'date' => 'Dátum',
        'account' => 'Účet',
        'amount' => 'Suma',
        'category' => 'Kategória',
        'date_range' => 'Rozsah dátumov',
        'from' => 'Od',
        'to' => 'Do',
        'custom_range' => 'Vlastný rozsah ×',
        'after' => 'Po :date ×',
        'before' => 'Pred :date ×',
        'dir_both' => 'Oboje',
        'dir_in' => 'Príjem',
        'dir_out' => 'Výdaj',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minimálna suma',
        'max_aria' => 'Maximálna suma',
        'after_aria' => 'Po dátume',
        'before_aria' => 'Pred dátumom',
        'acct' => ':count účet|:count účty|:count účtov',
        'cat' => ':count kategória|:count kategórie|:count kategórií',
        'date_dialog' => 'Filter dátumu',
        'account_dialog' => 'Filter účtu',
        'amount_dialog' => 'Filter sumy',
        'category_dialog' => 'Filter kategórie',
        'remove_date_aria' => 'Odstrániť filter dátumu',
        'remove_account_aria' => 'Odstrániť filter účtu',
        'remove_amount_aria' => 'Odstrániť filter sumy',
        'remove_category_aria' => 'Odstrániť filter kategórie',

        'remove_named_aria' => 'Odstrániť filter: :name',
    ],

    'date_preset' => [
        'this_month' => 'Tento mesiac',
        'last_month' => 'Minulý mesiac',
        'this_year' => 'Tento rok',
        'last_year' => 'Minulý rok',
    ],
];
