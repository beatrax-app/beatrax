<?php

declare(strict_types=1);

return [
    'heading' => 'Stałe płatności miesięczne',

    'summary' => [
        'expenses' => 'wydatki',
        'income' => 'przychody',
        'net' => 'netto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Wydatek',
        'income' => 'Przychód',
    ],

    'filter_aria' => 'Filtruj stałe płatności',
    'filter_all' => 'Wszystkie serie',
    'filter_this_month' => 'Tylko ten miesiąc',

    'empty_this_month' => 'W tym miesiącu nie przypada żadna seria cykliczna.',
    'empty_all' => 'Brak zatwierdzonych serii cyklicznych.',

    'chain' => 'łańcuch',
    'chain_aria' => 'Finansowane przez łańcuch',
    'per_month_suffix' => '/mies.',

    'view_all' => 'Zobacz wszystkie →',
];
