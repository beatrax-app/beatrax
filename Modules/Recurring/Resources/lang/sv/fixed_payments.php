<?php

declare(strict_types=1);

return [
    'heading' => 'Fasta månadsbetalningar',

    'summary' => [
        'expenses' => 'utgifter',
        'income' => 'inkomster',
        'net' => 'netto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Utgift',
        'income' => 'Inkomst',
    ],

    'filter_aria' => 'Filtrera fasta betalningar',
    'filter_all' => 'Alla serier',
    'filter_this_month' => 'Endast den här månaden',

    'empty_this_month' => 'Inga återkommande serier förfaller den här månaden.',
    'empty_all' => 'Inga godkända återkommande serier ännu.',

    'chain' => 'kedja',
    'chain_aria' => 'Finansieras via kedja',
    'per_month_suffix' => '/mån',

    'view_all' => 'Visa alla →',
];
