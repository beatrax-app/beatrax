<?php

declare(strict_types=1);

return [
    'heading' => 'Fixed monthly payments',

    'summary' => [
        'expenses' => 'expenses',
        'income' => 'income',
        'net' => 'net',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Expense',
        'income' => 'Income',
    ],

    'filter_aria' => 'Filter fixed payments',
    'filter_all' => 'All series',
    'filter_this_month' => 'This month only',

    'empty_this_month' => 'No recurring series are due this month.',
    'empty_all' => 'No approved recurring series yet.',

    'chain' => 'chain',
    'chain_aria' => 'Funded via chain',
    'per_month_suffix' => '/mo',

    'view_all' => 'View all →',
];
