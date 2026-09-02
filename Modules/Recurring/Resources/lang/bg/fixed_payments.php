<?php

declare(strict_types=1);

return [
    'heading' => 'Фиксирани месечни плащания',

    'summary' => [
        'expenses' => 'разходи',
        'income' => 'приходи',
        'net' => 'нето',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Разход',
        'income' => 'Приход',
    ],

    'filter_aria' => 'Филтрирай фиксираните плащания',
    'filter_all' => 'Всички поредици',
    'filter_this_month' => 'Само този месец',

    'empty_this_month' => 'Няма повтарящи се поредици с падеж този месец.',
    'empty_all' => 'Още няма одобрени повтарящи се поредици.',

    'chain' => 'верига',
    'chain_aria' => 'Финансирано чрез верига',
    'per_month_suffix' => '/мес.',

    'view_all' => 'Виж всички →',
];
