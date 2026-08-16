<?php

declare(strict_types=1);

return [
    'title' => 'Raporty',
    'page_title' => 'Raporty · Beatrax',
    'saved_report' => 'zapisany raport|zapisane raporty|zapisanych raportów',
    'pinned_count' => 'przypięte',
    'dismiss' => 'Odrzuć',

    'build_new' => 'Zbuduj nowy raport',
    'view_mode_aria' => 'Tryb widoku',
    'cards' => 'Karty',
    'list' => 'Lista',

    'empty' => [
        'heading' => 'Nie ma jeszcze zapisanych raportów',
        'body' => 'Zbuduj raport poniżej i zapisz go, aby zobaczyć go tutaj.',
        'cta' => 'Zbuduj swój pierwszy raport →',
    ],

    'pin' => [
        'pinned_aria' => 'Przypięty — odepnij od Pulpitu',
        'pin_aria' => 'Przypnij — przypnij do Pulpitu',
        'pinned_title' => 'Przypięty',
        'pin_title' => 'Przypnij do Pulpitu',
        'pinned_label' => 'Przypięty',
        'pin_label' => 'Przypnij',
    ],

    'open' => 'Otwórz',
    'edit' => 'Edytuj',

    'delete_confirm' => 'Usunąć „:name”?',
    'delete_report' => 'Usuń raport',
    'cancel' => 'Anuluj',
    'delete' => 'Usuń',
    'delete_aria' => 'Usuń: :name',

    'col' => [
        'name' => 'Nazwa',
        'summary' => 'Podsumowanie',
        'pinned' => 'Przypięty',
        'actions' => 'Akcje',
    ],

    'flash' => [
        'not_found' => 'Nie znaleziono raportu (mógł zostać usunięty w innej karcie).',
        'deleted' => 'Raport usunięty.',
    ],
    'pin_cap' => 'Możesz przypiąć maksymalnie 3 raporty. Odepnij jeden, aby dodać ten.',

    'summary' => [
        'metric' => [
            'spend' => 'Wydatki',
            'income' => 'Przychody',
            'net' => 'Netto',
            'net_worth' => 'Wartość netto',
            'fallback' => 'Kwota',
        ],
        'dimension' => [
            'category' => 'kategoria',
            'time_bucket' => 'przedział czasu',
            'counterparty' => 'kontrahent',
            'account' => 'konto',
            'fallback' => 'kategoria',
        ],
        'period' => [
            'this_month' => 'Ten miesiąc',
            'last_3_months' => 'Ostatnie 3 miesiące',
            'last_6_months' => 'Ostatnie 6 miesięcy',
            'last_12_months' => 'Ostatnie 12 miesięcy',
            'ytd' => 'Od początku roku',
            'this_year' => 'Ten rok',
            'custom' => 'Własny zakres',
        ],
        'with_dimension' => ':metric · podział: :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
