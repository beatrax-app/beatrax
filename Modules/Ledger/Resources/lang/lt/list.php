<?php

declare(strict_types=1);

return [
    'page_title' => 'Operacijos',
    'heading' => 'Operacijos',

    'subtitle_searching' => 'Ieškoma visoje istorijoje',
    'subtitle_full' => 'Visa istorija.',
    'subtitle_recent' => 'Naujausios operacijos (paskutinės 90 dienų).',

    'currency_aria' => 'Valiutos rodinys',
    'currency_eur' => 'Tik :code',
    'currency_original' => 'Originali valiuta',

    'show_recent' => 'Rodyti tik naujausias',
    'show_full' => 'Rodyti visą istoriją',

    'empty_period' => 'Šiuo laikotarpiu nieko nėra.',


    'empty_recent_has_older' => 'Per pastarąsias 90 dienų nieko. Senesnės operacijos vis dar čia.',

    'empty_history' => 'Operacijų dar nėra.',
    'loading_more' => 'Įkeliama daugiau operacijų',
    'load_more' => 'Įkelti daugiau',

    'split_badge' => 'Padalyta · :count',
    'split_expand_aria' => 'Padalyta tarp :count kategorijos — išskleisk, kad pamatytum|Padalyta tarp :count kategorijų — išskleisk, kad pamatytum|Padalyta tarp :count kategorijų — išskleisk, kad pamatytum',

    'chain_badge' => 'grandinė',
    'chain_title' => 'Grandinės dalis — atverk šią eilutę, kad pamatytum',

    'table' => [
        'date' => 'Data',
        'counterparty' => 'Kita šalis',
        'category' => 'Kategorija',
        'tax' => 'Mokesčiai',
        'status' => 'Būsena',
        'amount' => 'Suma',
    ],

    'search' => [
        'placeholder' => 'Ieškoti prekybininko, aprašymo, pastabų…',
        'placeholder_short' => 'Ieškoti operacijų…',
        'aria' => 'Ieškoti operacijų',
        'clear_all' => 'Išvalyti viską',
        'filters' => 'Filtrai',
        'open_filters_aria' => 'Atverti filtrus',
        'apply' => 'Taikyti',
        'clear' => 'Išvalyti',

        'count' => ':count operacija|:count operacijos|:count operacijų',
        'matching_suffix' => 'atitinka filtrus',
        'flow' => 'išleista :out / gauta :in',
    ],

    'no_results' => [
        'heading' => 'Nieko neatitinka',
        'remove_prompt' => 'Pabandyk pašalinti filtrą, kuris galbūt siaurina rezultatus:',
        'no_match_query' => 'Visoje istorijoje nėra operacijų, atitinkančių „:query“.',
        'no_match_filters' => 'Nė viena operacija neatitinka pritaikytų filtrų.',
        'did_you_mean' => 'Gal turėjai omenyje:',
        'account_fallback' => 'Sąskaita :id',
        'category_fallback' => 'Kategorija :id',
    ],

    'filter' => [
        'date' => 'Data',
        'account' => 'Sąskaita',
        'amount' => 'Suma',
        'category' => 'Kategorija',
        'date_range' => 'Laikotarpis',
        'from' => 'Nuo',
        'to' => 'Iki',
        'custom_range' => 'Pasirinktas laikotarpis ×',
        'after' => 'Po :date ×',
        'before' => 'Iki :date ×',
        'dir_both' => 'Abi',
        'dir_in' => 'Pajamos',
        'dir_out' => 'Išlaidos',
        'min' => 'Min.',
        'max' => 'Maks.',
        'min_aria' => 'Mažiausia suma',
        'max_aria' => 'Didžiausia suma',
        'after_aria' => 'Data nuo',
        'before_aria' => 'Data iki',
        'acct' => ':count sąskaita|:count sąskaitos|:count sąskaitų',
        'cat' => ':count kategorija|:count kategorijos|:count kategorijų',
        'date_dialog' => 'Datos filtras',
        'account_dialog' => 'Sąskaitos filtras',
        'amount_dialog' => 'Sumos filtras',
        'category_dialog' => 'Kategorijos filtras',
        'remove_date_aria' => 'Pašalinti datos filtrą',
        'remove_account_aria' => 'Pašalinti sąskaitos filtrą',
        'remove_amount_aria' => 'Pašalinti sumos filtrą',
        'remove_category_aria' => 'Pašalinti kategorijos filtrą',

        'remove_named_aria' => 'Pašalinti filtrą :name',
    ],

    'date_preset' => [
        'this_month' => 'Šis mėnuo',
        'last_month' => 'Praėjęs mėnuo',
        'this_year' => 'Šie metai',
        'last_year' => 'Praėję metai',
    ],
];
