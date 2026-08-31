<?php

declare(strict_types=1);

return [
    'help_paypal' => 'Eksporty z PayPal nie zawierają wierszy z saldem, więc ustaw je ręcznie.',
    'help_default' => 'Zmień tylko wtedy, gdy wiesz, że bieżące rzeczywiste saldo różni się od tego, które wylicza Beatrax.',

    'legend' => 'Prognozowane saldo otwarcia — :name',
    'opening_label' => 'Saldo otwarcia',
    'opening_placeholder' => 'np. :amount',
    'as_of_label' => 'Saldo otwarcia na dzień',
    'as_of_help' => 'Data, na którą powyższa kwota jest prawdziwa.',

    'divergence' => 'To ponad :threshold różnicy w stosunku do salda, które Beatrax wylicza z zaimportowanych transakcji. Na pewno?',
    'computed_is' => 'Beatrax wylicza :amount.',
    'use_beatrax' => 'Użyj wartości z Beatrax',
    'use_mine' => 'Użyj mojej wartości',

    'save' => 'Zapisz saldo otwarcia',
    'remove' => 'Usuń saldo otwarcia',
    'saved' => 'Zapisano.',
    'removed' => 'Usunięto.',

    'toast' => [
        'updated' => 'Saldo otwarcia zaktualizowane.',
        'removed' => 'Saldo otwarcia usunięte.',
    ],

    'errors' => [
        'invalid_number' => 'Saldo otwarcia musi być prawidłową liczbą.',
        'date_required' => 'Wybierz datę, której dotyczy to saldo otwarcia.',
        'date_invalid' => 'Data salda otwarcia musi być prawidłową datą ISO (YYYY-MM-DD).',
        'date_future' => 'Data salda otwarcia nie może być w przyszłości.',
    ],
];
