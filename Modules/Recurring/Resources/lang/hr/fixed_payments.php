<?php

declare(strict_types=1);

return [
    'heading' => 'Fiksna mjesečna plaćanja',

    'summary' => [
        'expenses' => 'troškovi',
        'income' => 'prihodi',
        'net' => 'neto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Trošak',
        'income' => 'Prihod',
    ],

    'filter_aria' => 'Filtriraj fiksna plaćanja',
    'filter_all' => 'Sve serije',
    'filter_this_month' => 'Samo ovaj mjesec',

    'empty_this_month' => 'Ovaj mjesec ne dospijeva nijedna ponavljajuća serija.',
    'empty_all' => 'Još nema odobrenih ponavljajućih serija.',

    'chain' => 'lanac',
    'chain_aria' => 'Financirano putem lanca',
    'per_month_suffix' => '/mj.',

    'view_all' => 'Prikaži sve →',
];
