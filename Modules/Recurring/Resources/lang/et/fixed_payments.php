<?php

declare(strict_types=1);

return [
    'heading' => 'Püsivad kuumaksed',

    'summary' => [
        'expenses' => 'kulud',
        'income' => 'tulud',
        'net' => 'neto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Kulu',
        'income' => 'Tulu',
    ],

    'filter_aria' => 'Filtreeri püsimakseid',
    'filter_all' => 'Kõik seeriad',
    'filter_this_month' => 'Ainult see kuu',

    'empty_this_month' => 'Sel kuul ei ole ühtegi korduvmaksete seeriat tähtajaks.',
    'empty_all' => 'Kinnitatud korduvmaksete seeriaid veel pole.',

    'chain' => 'ahel',
    'chain_aria' => 'Rahastatud ahela kaudu',
    'per_month_suffix' => '/kuus',

    'view_all' => 'Vaata kõiki →',
];
