<?php

declare(strict_types=1);

return [
    'title' => 'Raportit',
    'page_title' => 'Raportit · Beatrax',
    'saved_report' => ':count tallennettu raportti|:count tallennettua raporttia',
    'pinned_count' => ':count/:max kiinnitetty|:count/:max kiinnitetty',
    'dismiss' => 'Ohita',

    'build_new' => 'Rakenna uusi raportti',
    'view_mode_aria' => 'Näkymätila',
    'cards' => 'Kortit',
    'list' => 'Lista',

    'empty' => [
        'heading' => 'Ei vielä tallennettuja raportteja',
        'body' => 'Rakenna raportti alla ja tallenna se, niin se näkyy tässä.',
        'cta' => 'Rakenna ensimmäinen raporttisi →',
    ],

    'pin' => [
        'pinned_aria' => 'Kiinnitetty — poista kiinnitys yleisnäkymästä',
        'pin_aria' => 'Kiinnitä — kiinnitä yleisnäkymään',
        'pinned_title' => 'Kiinnitetty',
        'pin_title' => 'Kiinnitä yleisnäkymään',
        'pinned_label' => 'Kiinnitetty',
        'pin_label' => 'Kiinnitä',
    ],

    'open' => 'Avaa',
    'edit' => 'Muokkaa',

    'delete_confirm' => 'Poistetaanko ":name"?',
    'delete_report' => 'Poista raportti',
    'cancel' => 'Peruuta',
    'delete' => 'Poista',
    'delete_aria' => 'Poista :name',

    'col' => [
        'name' => 'Nimi',
        'summary' => 'Yhteenveto',
        'pinned' => 'Kiinnitetty',
        'actions' => 'Toiminnot',
    ],

    'flash' => [
        'not_found' => 'Raporttia ei löytynyt (se on ehkä poistettu toisessa välilehdessä).',
        'deleted' => 'Raportti poistettu.',
    ],
    'pin_cap' => 'Voit kiinnittää enintään :max raporttia. Poista jonkin kiinnitys, niin voit lisätä tämän.',

    'summary' => [
        'metric' => [
            'spend' => 'Kulutus',
            'income' => 'Tulot',
            'net' => 'Netto',
            'net_worth' => 'Nettovarallisuus',
            'fallback' => 'Summa',
        ],
        'dimension' => [
            'category' => 'kategoria',
            'time_bucket' => 'aikajakso',
            'counterparty' => 'vastapuoli',
            'account' => 'tili',
            'fallback' => 'kategoria',
        ],
        'period' => [
            'this_month' => 'Tämä kuukausi',
            'last_3_months' => 'Viimeiset 3 kuukautta',
            'last_6_months' => 'Viimeiset 6 kuukautta',
            'last_12_months' => 'Viimeiset 12 kuukautta',
            'ytd' => 'Vuoden alusta',
            'this_year' => 'Tämä vuosi',
            'custom' => 'Mukautettu aikaväli',
        ],
        'with_dimension' => ':metric · ryhmittely: :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
