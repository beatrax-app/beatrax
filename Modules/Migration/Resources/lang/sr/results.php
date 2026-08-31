<?php

declare(strict_types=1);

return [
    'page_title' => 'Uvoz završen',

    'heading_complete' => 'Uvoz završen',
    'heading_update' => 'Ažuriranje primenjeno',

    'summary_line' => 'Uvezeno: :categories, :budget_months i :transactions.',
    'summary_categories' => ':count kategorija|:count kategorije|:count kategorija',
    'summary_budget_months' => ':count budžetski mesec|:count budžetska meseca|:count budžetskih meseci',
    'summary_transactions' => ':count transakcija|:count transakcije|:count transakcija',
    'summary_attention' => ':count stavka još traži tvoju pažnju — pogledaj ispod.|:count stavke još traže tvoju pažnju — pogledaj ispod.|:count stavki još traži tvoju pažnju — pogledaj ispod.',

    'stats' => [
        'category' => 'Kategorije',
        'account' => 'Računi',
        // i18n-review: sr · stats.payee — the count is payees the import
        // linked, not ones it created; check the participle agrees here.
        'payee' => 'Povezane druge strane',
        'transaction' => 'Transakcije',
        'budget' => 'Budžetski meseci',
    ],

    'groups' => [
        'extra' => 'Nije uvezeno',
        'conflict' => 'Traži tvoju odluku',
    ],

    'view_transactions' => 'Prikaži transakcije',
    'view_budgets' => 'Prikaži budžete',
    'back' => 'Nazad na migracije',
];
