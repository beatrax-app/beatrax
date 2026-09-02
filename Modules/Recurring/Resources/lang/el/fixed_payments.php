<?php

declare(strict_types=1);

return [
    'heading' => 'Πάγιες μηνιαίες πληρωμές',

    'summary' => [
        'expenses' => 'έξοδα',
        'income' => 'έσοδα',
        'net' => 'καθαρό',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Έξοδο',
        'income' => 'Έσοδο',
    ],

    'filter_aria' => 'Φιλτράρισμα πάγιων πληρωμών',
    'filter_all' => 'Όλες οι σειρές',
    'filter_this_month' => 'Μόνο αυτόν τον μήνα',

    'empty_this_month' => 'Δεν υπάρχουν επαναλαμβανόμενες σειρές με πληρωμή αυτόν τον μήνα.',
    'empty_all' => 'Δεν υπάρχουν ακόμη εγκεκριμένες επαναλαμβανόμενες σειρές.',

    'chain' => 'αλυσίδα',
    'chain_aria' => 'Χρηματοδοτείται μέσω αλυσίδας',
    'per_month_suffix' => '/μήνα',

    'view_all' => 'Προβολή όλων →',
];
