<?php

declare(strict_types=1);

return [
    'page_title' => 'Tranzakciók',
    'heading' => 'Tranzakciók',

    'subtitle_searching' => 'Keresés a teljes előzményben',
    'subtitle_full' => 'Teljes előzmény.',
    'subtitle_recent' => 'Legutóbbi tranzakciók (utolsó 90 nap).',

    'currency_aria' => 'Devizanézet',
    'currency_eur' => 'Csak EUR',
    'currency_original' => 'Eredeti deviza',

    'show_recent' => 'Csak a legutóbbiak',
    'show_full' => 'Teljes előzmény mutatása',

    'empty_period' => 'Ebben az időszakban nincs semmi.',

    'loading_more' => 'További tranzakciók betöltése',
    'load_more' => 'Továbbiak betöltése',

    'split_badge' => 'Felosztás · :count',
    'split_expand_aria' => ':count kategória között felosztva — nyisd ki a megtekintéshez',

    'chain_badge' => 'lánc',
    'chain_title' => 'Egy lánc része — nyisd meg ezt a sort a megtekintéshez',

    'table' => [
        'date' => 'Dátum',
        'counterparty' => 'Partner',
        'category' => 'Kategória',
        'tax' => 'Adó',
        'status' => 'Állapot',
        'amount' => 'Összeg',
    ],

    'search' => [
        'placeholder' => 'Kereskedő, leírás, megjegyzés keresése…',
        'placeholder_short' => 'Tranzakciók keresése…',
        'aria' => 'Tranzakciók keresése',
        'clear_all' => 'Összes törlése',
        'filters' => 'Szűrők',
        'open_filters_aria' => 'Szűrők megnyitása',
        'apply' => 'Alkalmaz',
        'clear' => 'Törlés',

        'count' => ':count tranzakció|:count tranzakció',
        'matching_suffix' => 'illeszkedik a szűrőkre',
        'flow' => ':out ki / :in be',
    ],

    'no_results' => [
        'heading' => 'Nincs találat',
        'remove_prompt' => 'Próbálj eltávolítani egy szűrőt, amely szűkítheti a találatokat:',
        'no_match_query' => 'A teljes előzményben egyetlen tranzakció sem illeszkedik erre: „:query”.',
        'no_match_filters' => 'Egyetlen tranzakció sem illeszkedik az alkalmazott szűrőkre.',
        'did_you_mean' => 'Erre gondoltál:',
        'account_fallback' => 'Számla :id',
        'category_fallback' => 'Kategória :id',
    ],

    'filter' => [
        'date' => 'Dátum',
        'account' => 'Számla',
        'amount' => 'Összeg',
        'category' => 'Kategória',
        'date_range' => 'Időszak',
        'from' => 'Ettől',
        'to' => 'Eddig',
        'custom_range' => 'Egyéni időszak ×',
        'after' => ':date után ×',
        'before' => ':date előtt ×',
        'dir_both' => 'Mindkettő',
        'dir_in' => 'Be',
        'dir_out' => 'Ki',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minimális összeg',
        'max_aria' => 'Maximális összeg',
        'after_aria' => 'Dátum után',
        'before_aria' => 'Dátum előtt',
        'acct' => ':count számla|:count számla',
        'cat' => ':count kategória|:count kategória',
        'date_dialog' => 'Dátumszűrő',
        'account_dialog' => 'Számlaszűrő',
        'amount_dialog' => 'Összegszűrő',
        'category_dialog' => 'Kategóriaszűrő',
        'remove_date_aria' => 'Dátumszűrő eltávolítása',
        'remove_account_aria' => 'Számlaszűrő eltávolítása',
        'remove_amount_aria' => 'Összegszűrő eltávolítása',
        'remove_category_aria' => 'Kategóriaszűrő eltávolítása',

        'remove_named_aria' => ':name szűrő eltávolítása',
    ],

    'date_preset' => [
        'this_month' => 'Ez a hónap',
        'last_month' => 'Előző hónap',
        'this_year' => 'Ez az év',
        'last_year' => 'Előző év',
    ],
];
