<?php

declare(strict_types=1);

return [
    'heading' => 'Pagamenti fissi mensili',

    'summary' => [
        'expenses' => 'spese',
        'income' => 'entrate',
        'net' => 'netto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Spesa',
        'income' => 'Entrata',
    ],

    'filter_aria' => 'Filtra i pagamenti fissi',
    'filter_all' => 'Tutte le serie',
    'filter_this_month' => 'Solo questo mese',

    'empty_this_month' => 'Nessuna serie ricorrente in scadenza questo mese.',
    'empty_all' => 'Ancora nessuna serie ricorrente approvata.',

    'chain' => 'catena',
    'chain_aria' => 'Alimentato tramite catena',
    'per_month_suffix' => '/mese',

    'view_all' => 'Vedi tutti →',
];
