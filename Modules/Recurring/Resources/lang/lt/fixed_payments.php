<?php

declare(strict_types=1);

return [
    'heading' => 'Fiksuoti mėnesiniai mokėjimai',

    'summary' => [
        'expenses' => 'išlaidos',
        'income' => 'pajamos',
        'net' => 'grynasis',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Išlaidos',
        'income' => 'Pajamos',
    ],

    'filter_aria' => 'Filtruoti fiksuotus mokėjimus',
    'filter_all' => 'Visos serijos',
    'filter_this_month' => 'Tik šį mėnesį',

    'empty_this_month' => 'Šį mėnesį mokėtinų pasikartojančių serijų nėra.',
    'empty_all' => 'Patvirtintų pasikartojančių serijų dar nėra.',

    'chain' => 'grandinė',
    'chain_aria' => 'Finansuojama per grandinę',
    'per_month_suffix' => '/mėn.',

    'view_all' => 'Rodyti visus →',
];
