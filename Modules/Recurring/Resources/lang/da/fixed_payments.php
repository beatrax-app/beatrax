<?php

declare(strict_types=1);

return [
    'heading' => 'Faste månedlige betalinger',

    'summary' => [
        'expenses' => 'udgifter',
        'income' => 'indtægter',
        'net' => 'netto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Udgift',
        'income' => 'Indtægt',
    ],

    'filter_aria' => 'Filtrér faste betalinger',
    'filter_all' => 'Alle serier',
    'filter_this_month' => 'Kun denne måned',

    'empty_this_month' => 'Ingen tilbagevendende serier forfalder denne måned.',
    'empty_all' => 'Ingen godkendte tilbagevendende serier endnu.',

    'chain' => 'kæde',
    'chain_aria' => 'Finansieres via kæde',
    'per_month_suffix' => '/md',

    'view_all' => 'Vis alle →',
];
