<?php

declare(strict_types=1);

return [
    'heading' => 'Fix havi fizetések',

    'summary' => [
        'expenses' => 'kiadás',
        'income' => 'bevétel',
        'net' => 'nettó',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Kiadás',
        'income' => 'Bevétel',
    ],

    'filter_aria' => 'Fix fizetések szűrése',
    'filter_all' => 'Összes sorozat',
    'filter_this_month' => 'Csak ez a hónap',

    'empty_this_month' => 'Ebben a hónapban nincs esedékes ismétlődő sorozat.',
    'empty_all' => 'Még nincs jóváhagyott ismétlődő sorozat.',

    'chain' => 'lánc',
    'chain_aria' => 'Láncon keresztül fedezve',
    'per_month_suffix' => '/hó',

    'view_all' => 'Összes megtekintése →',
];
