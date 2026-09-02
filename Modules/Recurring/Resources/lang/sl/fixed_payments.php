<?php

declare(strict_types=1);

return [
    'heading' => 'Fiksna mesečna plačila',

    'summary' => [
        'expenses' => 'odhodki',
        'income' => 'prihodki',
        'net' => 'neto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Strošek',
        'income' => 'Prihodek',
    ],

    'filter_aria' => 'Filtriraj fiksna plačila',
    'filter_all' => 'Vse serije',
    'filter_this_month' => 'Samo ta mesec',

    'empty_this_month' => 'Ta mesec ne zapade nobena ponavljajoča se serija.',
    'empty_all' => 'Odobrenih ponavljajočih se serij še ni.',

    'chain' => 'veriga',
    'chain_aria' => 'Financirano prek verige',
    'per_month_suffix' => '/mes.',

    'view_all' => 'Prikaži vse →',
];
