<?php

declare(strict_types=1);

return [
    'page_title' => 'Import on lõpetatud',

    'heading_complete' => 'Import on lõpetatud',
    'heading_update' => 'Uuendus on rakendatud',

    'summary_line' => 'Imporditud :categories, :budget_months ja :transactions.',
    'summary_categories' => ':count kategooria|:count kategooriat',
    'summary_budget_months' => ':count eelarvekuu|:count eelarvekuud',
    'summary_transactions' => ':count tehing|:count tehingut',
    'summary_attention' => ':count kirje vajab endiselt tähelepanu — vaata allpool.|:count kirjet vajab endiselt tähelepanu — vaata allpool.',

    'stats' => [
        'category' => 'Kategooriad',
        'account' => 'Kontod',
        // i18n-review: et · stats.payee — the count is payees the import
        // linked, not ones it created; check the participle agrees here.
        'payee' => 'Seotud vastaspooled',
        'transaction' => 'Tehingud',
        'budget' => 'Eelarvekuud',
    ],

    'groups' => [
        'extra' => 'Ei imporditud',
        'conflict' => 'Vajab sinu otsust',
    ],

    'view_transactions' => 'Vaata tehinguid',
    'view_budgets' => 'Vaata eelarveid',
    'back' => 'Tagasi ülekandmiste juurde',
];
