<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakce',
    'heading' => 'Transakce',

    'subtitle_searching' => 'Prohledává se celá historie',
    'subtitle_full' => 'Celá historie.',
    'subtitle_recent' => 'Nedávné transakce (posledních 90 dní).',

    'currency_aria' => 'Zobrazení měny',
    'currency_eur' => 'Pouze :code',
    'currency_original' => 'Původní měna',

    'show_recent' => 'Zobrazit jen nedávné',
    'show_full' => 'Zobrazit celou historii',

    'empty_period' => 'Za toto období tu nic není.',


    'empty_recent_has_older' => 'Za posledních 90 dní nic. Vaše starší transakce tu stále jsou.',

    'empty_history' => 'Zatím žádné transakce.',
    'loading_more' => 'Načítají se další transakce',
    'load_more' => 'Načíst další',

    'split_badge' => 'Rozdělení · :count',
    'split_expand_aria' => 'Rozděleno mezi :count kategorii — rozbal pro zobrazení|Rozděleno mezi :count kategorie — rozbal pro zobrazení|Rozděleno mezi :count kategorií — rozbal pro zobrazení',

    'chain_badge' => 'řetězec',
    'chain_title' => 'Součást řetězce — otevři tento řádek a uvidíš ho',

    'table' => [
        'date' => 'Datum',
        'counterparty' => 'Protistrana',
        'category' => 'Kategorie',
        'tax' => 'Daň',
        'status' => 'Stav',
        'amount' => 'Částka',
    ],

    'search' => [
        'placeholder' => 'Hledej obchodníka, popis, poznámky…',
        'placeholder_short' => 'Hledat transakce…',
        'aria' => 'Hledat transakce',
        'clear_all' => 'Vymazat vše',
        'filters' => 'Filtry',
        'open_filters_aria' => 'Otevřít filtry',
        'apply' => 'Použít',
        'clear' => 'Vymazat',

        'count' => ':count transakce|:count transakce|:count transakcí',
        'matching_suffix' => 'podle filtrů',
        'flow' => ':out odchozí / :in příchozí',
    ],

    'no_results' => [
        'heading' => 'Nic neodpovídá',
        'remove_prompt' => 'Zkus odebrat filtr, který může výsledky zužovat:',
        'no_match_query' => 'V celé historii neodpovídá dotazu „:query“ žádná transakce.',
        'no_match_filters' => 'Použitým filtrům neodpovídá žádná transakce.',
        'did_you_mean' => 'Možná hledáš:',
        'account_fallback' => 'Účet :id',
        'category_fallback' => 'Kategorie :id',
    ],

    'filter' => [
        'date' => 'Datum',
        'account' => 'Účet',
        'amount' => 'Částka',
        'category' => 'Kategorie',
        'date_range' => 'Rozsah dat',
        'from' => 'Od',
        'to' => 'Do',
        'custom_range' => 'Vlastní rozsah ×',
        'after' => 'Po :date ×',
        'before' => 'Před :date ×',
        'dir_both' => 'Obojí',
        'dir_in' => 'Příchozí',
        'dir_out' => 'Odchozí',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minimální částka',
        'max_aria' => 'Maximální částka',
        'after_aria' => 'Po datu',
        'before_aria' => 'Před datem',
        'acct' => ':count účet|:count účty|:count účtů',
        'cat' => ':count kategorie|:count kategorie|:count kategorií',
        'date_dialog' => 'Filtr data',
        'account_dialog' => 'Filtr účtu',
        'amount_dialog' => 'Filtr částky',
        'category_dialog' => 'Filtr kategorie',
        'remove_date_aria' => 'Odebrat filtr data',
        'remove_account_aria' => 'Odebrat filtr účtu',
        'remove_amount_aria' => 'Odebrat filtr částky',
        'remove_category_aria' => 'Odebrat filtr kategorie',

        'remove_named_aria' => 'Odebrat filtr: :name',
    ],

    'date_preset' => [
        'this_month' => 'Tento měsíc',
        'last_month' => 'Minulý měsíc',
        'this_year' => 'Tento rok',
        'last_year' => 'Minulý rok',
    ],
];
