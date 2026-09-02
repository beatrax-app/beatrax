<?php

declare(strict_types=1);

return [
    'heading' => 'Pevné mesačné platby',

    'summary' => [
        'expenses' => 'výdavky',
        'income' => 'príjmy',
        'net' => 'netto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Výdavok',
        'income' => 'Príjem',
    ],

    'filter_aria' => 'Filtrovať pevné platby',
    'filter_all' => 'Všetky série',
    'filter_this_month' => 'Iba tento mesiac',

    'empty_this_month' => 'Tento mesiac nie je splatná žiadna opakovaná séria.',
    'empty_all' => 'Zatiaľ žiadne schválené opakované série.',

    'chain' => 'reťazec',
    'chain_aria' => 'Financované cez reťazec',
    'per_month_suffix' => '/mes.',

    'view_all' => 'Zobraziť všetko →',
];
