<?php

declare(strict_types=1);

return [
    'page_title' => 'Tehingud',
    'heading' => 'Tehingud',

    'subtitle_searching' => 'Otsin kogu ajaloost',
    'subtitle_full' => 'Kogu ajalugu.',
    'subtitle_recent' => 'Hiljutised tehingud (viimased 90 päeva).',

    'currency_aria' => 'Valuutavaade',
    'currency_eur' => 'Ainult EUR',
    'currency_original' => 'Algne valuuta',

    'show_recent' => 'Näita ainult hiljutisi',
    'show_full' => 'Näita kogu ajalugu',

    'empty_period' => 'Sel perioodil pole siin midagi.',

    'loading_more' => 'Laadin veel tehinguid',
    'load_more' => 'Laadi veel',

    'split_badge' => 'Jaotatud · :count',
    'split_expand_aria' => 'Jaotatud :count kategooria vahel — vaatamiseks laienda|Jaotatud :count kategooria vahel — vaatamiseks laienda',

    'chain_badge' => 'ahel',
    'chain_title' => 'Osa ahelast — vaatamiseks ava see rida',

    'table' => [
        'date' => 'Kuupäev',
        'counterparty' => 'Vastaspool',
        'category' => 'Kategooria',
        'tax' => 'Maks',
        'status' => 'Olek',
        'amount' => 'Summa',
    ],

    'search' => [
        'placeholder' => 'Otsi kaupmeest, kirjeldust, märkusi…',
        'placeholder_short' => 'Otsi tehinguid…',
        'aria' => 'Otsi tehinguid',
        'clear_all' => 'Tühjenda kõik',
        'filters' => 'Filtrid',
        'open_filters_aria' => 'Ava filtrid',
        'apply' => 'Rakenda',
        'clear' => 'Tühjenda',

        'count' => ':count tehing|:count tehingut',
        'matching_suffix' => 'vastab filtritele',
        'flow' => ':out välja / :in sisse',
    ],

    'no_results' => [
        'heading' => 'Midagi ei sobi',
        'remove_prompt' => 'Proovi eemaldada filter, mis võib tulemusi kitsendada:',
        'no_match_query' => 'Ükski tehing kogu ajaloos ei vasta päringule „:query“.',
        'no_match_filters' => 'Ükski tehing ei vasta rakendatud filtritele.',
        'did_you_mean' => 'Kas mõtlesid:',
        'account_fallback' => 'Konto :id',
        'category_fallback' => 'Kategooria :id',
    ],

    'filter' => [
        'date' => 'Kuupäev',
        'account' => 'Konto',
        'amount' => 'Summa',
        'category' => 'Kategooria',
        'date_range' => 'Kuupäevavahemik',
        'from' => 'Alates',
        'to' => 'Kuni',
        'custom_range' => 'Kohandatud vahemik ×',
        'after' => 'Pärast :date ×',
        'before' => 'Enne :date ×',
        'dir_both' => 'Mõlemad',
        'dir_in' => 'Sisse',
        'dir_out' => 'Välja',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Miinimumsumma',
        'max_aria' => 'Maksimumsumma',
        'after_aria' => 'Pärast kuupäeva',
        'before_aria' => 'Enne kuupäeva',
        'acct' => ':count konto|:count kontot',
        'cat' => ':count kategooria|:count kategooriat',
        'date_dialog' => 'Kuupäevafilter',
        'account_dialog' => 'Kontofilter',
        'amount_dialog' => 'Summafilter',
        'category_dialog' => 'Kategooriafilter',
        'remove_date_aria' => 'Eemalda kuupäevafilter',
        'remove_account_aria' => 'Eemalda kontofilter',
        'remove_amount_aria' => 'Eemalda summafilter',
        'remove_category_aria' => 'Eemalda kategooriafilter',

        'remove_named_aria' => 'Eemalda filter :name',
    ],

    'date_preset' => [
        'this_month' => 'See kuu',
        'last_month' => 'Eelmine kuu',
        'this_year' => 'See aasta',
        'last_year' => 'Eelmine aasta',
    ],
];
