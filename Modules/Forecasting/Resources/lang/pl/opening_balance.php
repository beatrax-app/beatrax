<?php

declare(strict_types=1);

return [
    'help_paypal' => 'Eksporty z PayPal nie zawierają wierszy z saldem, więc ustaw je ręcznie.',
    'help_asn' => 'Ustawione automatycznie na podstawie najnowszego wyciągu. Zmień tylko wtedy, gdy wiesz, że rzeczywiste saldo jest inne.',
    'help_default' => 'Zmień tylko wtedy, gdy wiesz, że bieżące rzeczywiste saldo różni się od tego, które wylicza Beatrax.',

    'legend' => 'Prognozowane saldo otwarcia — :name',
    'opening_label' => 'Saldo otwarcia',
    'opening_placeholder' => 'np. 1.250,00',
    'as_of_label' => 'Saldo otwarcia na dzień',
    'as_of_help' => 'Data, na którą powyższa kwota jest prawdziwa.',

    'divergence' => 'To ponad 500 € różnicy w stosunku do salda, które Beatrax wylicza z zaimportowanych transakcji. Na pewno?',
    'use_beatrax' => 'Użyj wartości z Beatrax',
    'use_mine' => 'Użyj mojej wartości',

    'save' => 'Zapisz saldo otwarcia',
    'saved' => 'Zapisano.',

    'toast' => [
        'updated' => 'Saldo otwarcia zaktualizowane.',
    ],

    'errors' => [
        'invalid_number' => 'Saldo otwarcia musi być prawidłową liczbą.',
        'date_required' => 'Wybierz datę, której dotyczy to saldo otwarcia.',
        'date_invalid' => 'Data salda otwarcia musi być prawidłową datą ISO (YYYY-MM-DD).',
        'date_future' => 'Data salda otwarcia nie może być w przyszłości.',
    ],
];
