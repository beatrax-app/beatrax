<?php

declare(strict_types=1);

return [
    'heading' => 'Plăți fixe lunare',

    'summary' => [
        'expenses' => 'cheltuieli',
        'income' => 'venituri',
        'net' => 'net',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Cheltuială',
        'income' => 'Venit',
    ],

    'filter_aria' => 'Filtrează plățile fixe',
    'filter_all' => 'Toate seriile',
    'filter_this_month' => 'Doar luna aceasta',

    'empty_this_month' => 'Nicio serie recurentă scadentă luna aceasta.',
    'empty_all' => 'Nicio serie recurentă aprobată deocamdată.',

    'chain' => 'lanț',
    'chain_aria' => 'Finanțat prin lanț',
    'per_month_suffix' => '/lună',

    'view_all' => 'Vezi toate →',
];
