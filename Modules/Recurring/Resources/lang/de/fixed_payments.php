<?php

declare(strict_types=1);

return [
    'heading' => 'Feste monatliche Zahlungen',

    'summary' => [
        'expenses' => 'Ausgaben',
        'income' => 'Einnahmen',
        'net' => 'Netto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Ausgabe',
        'income' => 'Einnahme',
    ],

    'filter_aria' => 'Feste Zahlungen filtern',
    'filter_all' => 'Alle Reihen',
    'filter_this_month' => 'Nur diesen Monat',

    'empty_this_month' => 'In diesem Monat sind keine wiederkehrenden Reihen fällig.',
    'empty_all' => 'Noch keine bestätigten wiederkehrenden Reihen.',

    'chain' => 'Kette',
    'chain_aria' => 'Über eine Kette finanziert',
    'per_month_suffix' => '/Mon.',

    'view_all' => 'Alle ansehen →',
];
