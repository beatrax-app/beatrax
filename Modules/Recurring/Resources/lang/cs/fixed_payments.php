<?php

declare(strict_types=1);

return [
    'heading' => 'Pevné měsíční platby',

    'summary' => [
        'expenses' => 'výdaje',
        'income' => 'příjmy',
        'net' => 'netto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Výdaj',
        'income' => 'Příjem',
    ],

    'filter_aria' => 'Filtrovat pevné platby',
    'filter_all' => 'Všechny série',
    'filter_this_month' => 'Jen tento měsíc',

    'empty_this_month' => 'Tento měsíc nepřipadá žádná opakovaná série.',
    'empty_all' => 'Zatím žádné schválené opakované série.',

    'chain' => 'řetězec',
    'chain_aria' => 'Financováno přes řetězec',
    'per_month_suffix' => '/měs.',

    'view_all' => 'Zobrazit vše →',
];
