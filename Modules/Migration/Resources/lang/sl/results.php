<?php

declare(strict_types=1);

return [
    'page_title' => 'Uvoz končan',

    'heading_complete' => 'Uvoz končan',
    'heading_update' => 'Posodobitev uporabljena',

    'summary_line' => 'Uvoženo: :categories, :budget_months in :transactions.',
    'summary_categories' => ':count kategorija|:count kategoriji|:count kategorije|:count kategorij',
    'summary_budget_months' => ':count proračunski mesec|:count proračunska meseca|:count proračunski meseci|:count proračunskih mesecev',
    'summary_transactions' => ':count transakcija|:count transakciji|:count transakcije|:count transakcij',
    'summary_attention' => ':count postavka še potrebuje tvojo pozornost — glej spodaj.|:count postavki še potrebujeta tvojo pozornost — glej spodaj.|:count postavke še potrebujejo tvojo pozornost — glej spodaj.|:count postavk še potrebuje tvojo pozornost — glej spodaj.',

    'stats' => [
        'category' => 'Kategorije',
        'account' => 'Računi',
        // i18n-review: sl · stats.payee — the count is payees the import
        // linked, not ones it created; check the participle agrees here.
        'payee' => 'Povezane nasprotne stranke',
        'transaction' => 'Transakcije',
        'budget' => 'Proračunski meseci',
    ],

    'groups' => [
        'extra' => 'Ni uvoženo',
        'conflict' => 'Potrebuje tvojo odločitev',
    ],

    'view_transactions' => 'Prikaži transakcije',
    'view_budgets' => 'Prikaži proračune',
    'back' => 'Nazaj na migracije',
];
