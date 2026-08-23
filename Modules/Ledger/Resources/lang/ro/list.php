<?php

declare(strict_types=1);

return [
    'page_title' => 'Tranzacții',
    'heading' => 'Tranzacții',

    'subtitle_searching' => 'Se caută în tot istoricul',
    'subtitle_full' => 'Istoric complet.',
    'subtitle_recent' => 'Tranzacții recente (ultimele 90 de zile).',

    'currency_aria' => 'Vizualizare valută',
    'currency_eur' => 'Doar :code',
    'currency_original' => 'Valuta originală',

    'show_recent' => 'Arată doar recentele',
    'show_full' => 'Arată istoricul complet',

    'empty_period' => 'Nimic aici pentru această perioadă.',

    'loading_more' => 'Se încarcă mai multe tranzacții',
    'load_more' => 'Încarcă mai multe',

    'split_badge' => 'Împărțită · :count',
    'split_expand_aria' => 'Împărțită pe :count categorie — extinde pentru a vedea|Împărțită pe :count categorii — extinde pentru a vedea|Împărțită pe :count de categorii — extinde pentru a vedea',

    'chain_badge' => 'lanț',
    'chain_title' => 'Face parte dintr-un lanț — deschide acest rând pentru a vedea',

    'table' => [
        'date' => 'Dată',
        'counterparty' => 'Contraparte',
        'category' => 'Categorie',
        'tax' => 'Fiscal',
        'status' => 'Stare',
        'amount' => 'Sumă',
    ],

    'search' => [
        'placeholder' => 'Caută comerciant, descriere, note…',
        'placeholder_short' => 'Caută tranzacții…',
        'aria' => 'Caută tranzacții',
        'clear_all' => 'Șterge tot',
        'filters' => 'Filtre',
        'open_filters_aria' => 'Deschide filtrele',
        'apply' => 'Aplică',
        'clear' => 'Șterge',

        'count' => ':count tranzacție|:count tranzacții|:count de tranzacții',
        'matching_suffix' => 'care corespund filtrelor',
        'flow' => ':out ieșiri / :in intrări',
    ],

    'no_results' => [
        'heading' => 'Nicio potrivire',
        'remove_prompt' => 'Încearcă să elimini un filtru care ar putea restrânge rezultatele:',
        'no_match_query' => 'Nicio tranzacție din tot istoricul nu se potrivește cu „:query”.',
        'no_match_filters' => 'Nicio tranzacție nu se potrivește cu filtrele aplicate.',
        'did_you_mean' => 'Ai vrut să spui:',
        'account_fallback' => 'Cont :id',
        'category_fallback' => 'Categorie :id',
    ],

    'filter' => [
        'date' => 'Dată',
        'account' => 'Cont',
        'amount' => 'Sumă',
        'category' => 'Categorie',
        'date_range' => 'Interval de date',
        'from' => 'De la',
        'to' => 'Până la',
        'custom_range' => 'Interval personalizat ×',
        'after' => 'După :date ×',
        'before' => 'Înainte de :date ×',
        'dir_both' => 'Ambele',
        'dir_in' => 'Intrări',
        'dir_out' => 'Ieșiri',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Sumă minimă',
        'max_aria' => 'Sumă maximă',
        'after_aria' => 'După dată',
        'before_aria' => 'Înainte de dată',
        'acct' => ':count cont|:count conturi|:count de conturi',
        'cat' => ':count categorie|:count categorii|:count de categorii',
        'date_dialog' => 'Filtru de dată',
        'account_dialog' => 'Filtru de cont',
        'amount_dialog' => 'Filtru de sumă',
        'category_dialog' => 'Filtru de categorie',
        'remove_date_aria' => 'Elimină filtrul de dată',
        'remove_account_aria' => 'Elimină filtrul de cont',
        'remove_amount_aria' => 'Elimină filtrul de sumă',
        'remove_category_aria' => 'Elimină filtrul de categorie',

        'remove_named_aria' => 'Elimină filtrul :name',
    ],

    'date_preset' => [
        'this_month' => 'Luna aceasta',
        'last_month' => 'Luna trecută',
        'this_year' => 'Anul acesta',
        'last_year' => 'Anul trecut',
    ],
];
