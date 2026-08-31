<?php

declare(strict_types=1);

return [
    'page_title' => 'Import zakończony',

    'heading_complete' => 'Import zakończony',
    'heading_update' => 'Aktualizacja zastosowana',

    'summary_line' => 'Zaimportowano: :categories, :budget_months i :transactions.',
    'summary_categories' => ':count kategoria|:count kategorie|:count kategorii',
    'summary_budget_months' => ':count miesiąc budżetu|:count miesiące budżetu|:count miesięcy budżetu',
    'summary_transactions' => ':count transakcja|:count transakcje|:count transakcji',
    'summary_attention' => ':count pozycja wymaga uwagi — szczegóły poniżej.|:count pozycje wymagają uwagi — szczegóły poniżej.|:count pozycji wymaga uwagi — szczegóły poniżej.',

    'stats' => [
        'category' => 'Kategorie',
        'account' => 'Konta',
        // i18n-review: pl · stats.payee — the count is payees the import
        // linked, not ones it created; check the participle agrees here.
        'payee' => 'Powiązani kontrahenci',
        'transaction' => 'Transakcje',
        'budget' => 'Miesiące budżetu',
    ],

    'groups' => [
        'extra' => 'Niezaimportowane',
        'conflict' => 'Wymaga Twojej decyzji',
    ],

    'view_transactions' => 'Zobacz transakcje',
    'view_budgets' => 'Zobacz budżety',
    'back' => 'Powrót do migracji',
];
