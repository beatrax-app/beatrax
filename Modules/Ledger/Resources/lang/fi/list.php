<?php

declare(strict_types=1);

return [
    'page_title' => 'Tapahtumat',
    'heading' => 'Tapahtumat',

    'subtitle_searching' => 'Haetaan koko historiasta',
    'subtitle_full' => 'Koko historia.',
    'subtitle_recent' => 'Viimeaikaiset tapahtumat (viimeiset 90 päivää).',

    'currency_aria' => 'Valuuttanäkymä',
    'currency_eur' => 'Vain :code',
    'currency_original' => 'Alkuperäinen valuutta',

    'show_recent' => 'Näytä vain viimeaikaiset',
    'show_full' => 'Näytä koko historia',

    'empty_period' => 'Ei mitään tältä jaksolta.',


    'empty_recent_has_older' => 'Ei mitään viimeisten 90 päivän ajalta. Vanhemmat tapahtumasi ovat yhä tallessa.',

    'empty_history' => 'Ei vielä tapahtumia.',
    'loading_more' => 'Ladataan lisää tapahtumia',
    'load_more' => 'Lataa lisää',

    'split_badge' => 'Jaettu · :count',
    'split_expand_aria' => 'Jaettu :count kategoriaan — laajenna nähdäksesi|Jaettu :count kategoriaan — laajenna nähdäksesi',

    'chain_badge' => 'ketju',
    'chain_title' => 'Osa ketjua — avaa tämä rivi nähdäksesi',

    'table' => [
        'date' => 'Päivä',
        'counterparty' => 'Vastapuoli',
        'category' => 'Kategoria',
        'tax' => 'Vero',
        'status' => 'Tila',
        'amount' => 'Summa',
    ],

    'search' => [
        'placeholder' => 'Hae kauppiasta, kuvausta, muistiinpanoja…',
        'placeholder_short' => 'Hae tapahtumia…',
        'aria' => 'Hae tapahtumia',
        'clear_all' => 'Tyhjennä kaikki',
        'filters' => 'Suodattimet',
        'open_filters_aria' => 'Avaa suodattimet',
        'apply' => 'Ota käyttöön',
        'clear' => 'Tyhjennä',

        'count' => ':count tapahtuma|:count tapahtumaa',
        'matching_suffix' => 'vastaa suodattimia',
        'flow' => ':out ulos / :in sisään',
    ],

    'no_results' => [
        'heading' => 'Ei osumia',
        'remove_prompt' => 'Kokeile poistaa suodatin, joka voi kaventaa tuloksia:',
        'no_match_query' => 'Yksikään tapahtuma koko historiassa ei vastaa hakua “:query”.',
        'no_match_filters' => 'Yksikään tapahtuma ei vastaa käytössä olevia suodattimia.',
        'did_you_mean' => 'Tarkoititko:',
        'account_fallback' => 'Tili :id',
        'category_fallback' => 'Kategoria :id',
    ],

    'filter' => [
        'date' => 'Päivä',
        'account' => 'Tili',
        'amount' => 'Summa',
        'category' => 'Kategoria',
        'date_range' => 'Aikaväli',
        'from' => 'Alkaen',
        'to' => 'Asti',
        'custom_range' => 'Mukautettu aikaväli ×',
        'after' => ':date jälkeen ×',
        'before' => 'Ennen :date ×',
        'dir_both' => 'Molemmat',
        'dir_in' => 'Sisään',
        'dir_out' => 'Ulos',
        'min' => 'Vähintään',
        'max' => 'Enintään',
        'min_aria' => 'Vähimmäissumma',
        'max_aria' => 'Enimmäissumma',
        'after_aria' => 'Päivämäärän jälkeen',
        'before_aria' => 'Ennen päivämäärää',
        'acct' => ':count tili|:count tiliä',
        'cat' => ':count kategoria|:count kategoriaa',
        'date_dialog' => 'Päiväsuodatin',
        'account_dialog' => 'Tilisuodatin',
        'amount_dialog' => 'Summasuodatin',
        'category_dialog' => 'Kategoriasuodatin',
        'remove_date_aria' => 'Poista päiväsuodatin',
        'remove_account_aria' => 'Poista tilisuodatin',
        'remove_amount_aria' => 'Poista summasuodatin',
        'remove_category_aria' => 'Poista kategoriasuodatin',

        'remove_named_aria' => 'Poista suodatin :name',
    ],

    'date_preset' => [
        'this_month' => 'Tämä kuukausi',
        'last_month' => 'Viime kuukausi',
        'this_year' => 'Tämä vuosi',
        'last_year' => 'Viime vuosi',
    ],
];
