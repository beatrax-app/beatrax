<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 SALDO POCZĄTKOWE',
    'confirmed_aria' => 'potwierdzone',
    'on_date' => 'na :date',

    'detected_h3' => 'Wykryte saldo początkowe — :label',
    'confirm' => 'Potwierdź',
    'edit' => 'Edytuj',

    'conflict_h3' => 'Widzimy dwie wartości dla tego konta — która jest prawidłowa?',
    'conflict_legend' => 'Wybierz saldo początkowe',
    'conflict_from' => 'Źródło :source:',
    'conflict_helper' => 'Domyślnie wybieramy najwcześniejszą datę. Wskaż właściwą wartość albo wpisz ją ręcznie.',
    'edit_manually' => 'Edytuj ręcznie',

    'editing_h3' => 'Edytuj saldo początkowe — :label',
    'input_label' => 'SALDO POCZĄTKOWE',
    'minor_units' => '(najmniejsze jednostki)',
    'on_date_label' => 'NA DZIEŃ',
    'cancel' => 'Anuluj',
    'save' => 'Zapisz',

    'change' => 'Zmień',

    'manual_h3' => 'Wpisz saldo początkowe ręcznie — :label',
    'manual_lede' => 'Nie udało się automatycznie wykryć salda początkowego dla tego konta. Wpisz je ręcznie albo pomiń.',

    'unknown_state' => 'Nieznany stan karty. Odśwież kreator.',

    'errors' => [
        'account_not_set' => 'Konto nie zostało ustawione. Odśwież kreator.',
        'invalid_amount' => 'Wpisz prawidłową kwotę.',
        'amount_range' => 'Wpisz kwotę z zakresu od -10 mln € do 10 mln €.',
        'pick_date' => 'Wybierz datę.',
        'pick_valid_date' => 'Wybierz prawidłową datę.',
        'future_date' => 'Data salda początkowego nie może być w przyszłości.',
        'date_warning' => 'To jest później niż Twoja pierwsza zaimportowana transakcja (:date). Na Pulpicie mogą pojawić się transakcje sprzed tej daty.',
    ],
];
