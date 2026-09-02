<?php

declare(strict_types=1);

return [
    'heading' => 'Paiements mensuels fixes',

    'summary' => [
        'expenses' => 'dépenses',
        'income' => 'revenus',
        'net' => 'net',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Dépense',
        'income' => 'Revenu',
    ],

    'filter_aria' => 'Filtrer les paiements fixes',
    'filter_all' => 'Toutes les séries',
    'filter_this_month' => 'Ce mois-ci uniquement',

    'empty_this_month' => 'Aucune série récurrente due ce mois-ci.',
    'empty_all' => 'Aucune série récurrente approuvée pour l\'instant.',

    'chain' => 'chaîne',
    'chain_aria' => 'Financé via une chaîne',
    'per_month_suffix' => '/mois',

    'view_all' => 'Tout voir →',
];
