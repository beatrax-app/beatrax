<?php

declare(strict_types=1);

return [
    'heading' => 'Fiksētie ikmēneša maksājumi',

    'summary' => [
        'expenses' => 'izdevumi',
        'income' => 'ieņēmumi',
        'net' => 'neto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Izdevumi',
        'income' => 'Ieņēmumi',
    ],

    'filter_aria' => 'Filtrēt fiksētos maksājumus',
    'filter_all' => 'Visas sērijas',
    'filter_this_month' => 'Tikai šis mēnesis',

    'empty_this_month' => 'Šomēnes nav paredzēta neviena regulāra sērija.',
    'empty_all' => 'Vēl nav apstiprinātu regulāro sēriju.',

    'chain' => 'ķēde',
    'chain_aria' => 'Finansēts caur ķēdi',
    'per_month_suffix' => '/mēn.',

    'view_all' => 'Skatīt visus →',
];
