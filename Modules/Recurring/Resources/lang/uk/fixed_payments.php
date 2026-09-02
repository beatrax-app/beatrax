<?php

declare(strict_types=1);

return [
    'heading' => 'Фіксовані щомісячні платежі',

    'summary' => [
        'expenses' => 'витрати',
        'income' => 'надходження',
        'net' => 'сальдо',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Витрата',
        'income' => 'Надходження',
    ],

    'filter_aria' => 'Фільтр фіксованих платежів',
    'filter_all' => 'Усі серії',
    'filter_this_month' => 'Лише цей місяць',

    'empty_this_month' => 'Цього місяця немає регулярних серій до сплати.',
    'empty_all' => 'Затверджених регулярних серій ще немає.',

    'chain' => 'ланцюг',
    'chain_aria' => 'Профінансовано через ланцюг',
    'per_month_suffix' => '/міс.',

    'view_all' => 'Переглянути всі →',
];
