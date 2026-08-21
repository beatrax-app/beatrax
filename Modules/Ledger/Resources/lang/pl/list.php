<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakcje',
    'heading' => 'Transakcje',

    'subtitle_searching' => 'Przeszukiwanie całej historii',
    'subtitle_full' => 'Pełna historia.',
    'subtitle_recent' => 'Ostatnie transakcje (ostatnie 90 dni).',

    'currency_aria' => 'Widok waluty',
    'currency_eur' => 'Tylko EUR',
    'currency_original' => 'Waluta oryginalna',

    'show_recent' => 'Pokaż tylko ostatnie',
    'show_full' => 'Pokaż pełną historię',

    'empty_period' => 'Nic w tym okresie.',

    'loading_more' => 'Wczytywanie kolejnych transakcji',
    'load_more' => 'Wczytaj więcej',

    'split_badge' => 'Podział · :count',
    'split_expand_aria' => 'Podzielona na :count kategorię — rozwiń, aby zobaczyć|Podzielona na :count kategorie — rozwiń, aby zobaczyć|Podzielona na :count kategorii — rozwiń, aby zobaczyć',

    'chain_badge' => 'łańcuch',
    'chain_title' => 'Część łańcucha — otwórz ten wiersz, aby zobaczyć',

    'table' => [
        'date' => 'Data',
        'counterparty' => 'Kontrahent',
        'category' => 'Kategoria',
        'tax' => 'Podatek',
        'status' => 'Status',
        'amount' => 'Kwota',
    ],

    'search' => [
        'placeholder' => 'Szukaj sprzedawcy, opisu, notatek…',
        'placeholder_short' => 'Szukaj transakcji…',
        'aria' => 'Szukaj transakcji',
        'clear_all' => 'Wyczyść wszystko',
        'filters' => 'Filtry',
        'open_filters_aria' => 'Otwórz filtry',
        'apply' => 'Zastosuj',
        'clear' => 'Wyczyść',

        'count' => ':count transakcja|:count transakcje|:count transakcji',
        'matching_suffix' => 'dla wybranych filtrów',
        'flow' => ':out wych. / :in przych.',
    ],

    'no_results' => [
        'heading' => 'Brak dopasowań',
        'remove_prompt' => 'Spróbuj usunąć filtr, który może zawężać wyniki:',
        'no_match_query' => 'W całej historii nie ma transakcji pasujących do „:query”.',
        'no_match_filters' => 'Żadna transakcja nie pasuje do zastosowanych filtrów.',
        'did_you_mean' => 'Czy chodziło o:',
        'account_fallback' => 'Konto :id',
        'category_fallback' => 'Kategoria :id',
    ],

    'filter' => [
        'date' => 'Data',
        'account' => 'Konto',
        'amount' => 'Kwota',
        'category' => 'Kategoria',
        'date_range' => 'Zakres dat',
        'from' => 'Od',
        'to' => 'Do',
        'custom_range' => 'Własny zakres ×',
        'after' => 'Po :date ×',
        'before' => 'Przed :date ×',
        'dir_both' => 'Oba',
        'dir_in' => 'Przych.',
        'dir_out' => 'Wych.',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Kwota minimalna',
        'max_aria' => 'Kwota maksymalna',
        'after_aria' => 'Po dacie',
        'before_aria' => 'Przed datą',
        'acct' => ':count konto|:count konta|:count kont',
        'cat' => ':count kategoria|:count kategorie|:count kategorii',
        'date_dialog' => 'Filtr daty',
        'account_dialog' => 'Filtr konta',
        'amount_dialog' => 'Filtr kwoty',
        'category_dialog' => 'Filtr kategorii',
        'remove_date_aria' => 'Usuń filtr daty',
        'remove_account_aria' => 'Usuń filtr konta',
        'remove_amount_aria' => 'Usuń filtr kwoty',
        'remove_category_aria' => 'Usuń filtr kategorii',

        'remove_named_aria' => 'Usuń filtr: :name',
    ],

    'date_preset' => [
        'this_month' => 'Ten miesiąc',
        'last_month' => 'Poprzedni miesiąc',
        'this_year' => 'Ten rok',
        'last_year' => 'Poprzedni rok',
    ],
];
