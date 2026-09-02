<?php

declare(strict_types=1);

return [
    'heading' => 'Vaste maandelijkse betalingen',

    'summary' => [
        'expenses' => 'uitgaven',
        'income' => 'inkomsten',
        'net' => 'netto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Uitgave',
        'income' => 'Inkomsten',
    ],

    'filter_aria' => 'Vaste betalingen filteren',
    'filter_all' => 'Alle reeksen',
    'filter_this_month' => 'Alleen deze maand',

    'empty_this_month' => 'Er zijn deze maand geen terugkerende reeksen verschuldigd.',
    'empty_all' => 'Nog geen goedgekeurde terugkerende reeksen.',

    'chain' => 'keten',
    'chain_aria' => 'Gefinancierd via keten',
    'per_month_suffix' => '/mnd',

    'view_all' => 'Alles bekijken →',
];
