<?php

declare(strict_types=1);

return [
    'page_title' => 'Import dokončen',

    'heading_complete' => 'Import dokončen',
    'heading_update' => 'Aktualizace použita',

    'summary_line' => 'Naimportováno: :categories, :budget_months a :transactions.',
    'summary_categories' => ':count kategorie|:count kategorie|:count kategorií',
    'summary_budget_months' => ':count měsíc rozpočtu|:count měsíce rozpočtu|:count měsíců rozpočtu',
    'summary_transactions' => ':count transakce|:count transakce|:count transakcí',
    'summary_attention' => ':count položka ještě potřebuje pozornost — viz níže.|:count položky ještě potřebují pozornost — viz níže.|:count položek ještě potřebuje pozornost — viz níže.',

    'stats' => [
        'category' => 'Kategorie',
        'account' => 'Účty',
        // i18n-review: cs · stats.payee — the count is payees the import
        // linked, not ones it created; check the participle agrees here.
        'payee' => 'Spárované protistrany',
        'transaction' => 'Transakce',
        'budget' => 'Měsíce rozpočtu',
    ],

    'groups' => [
        'extra' => 'Nenaimportováno',
        'conflict' => 'Vyžaduje tvoje rozhodnutí',
    ],

    'view_transactions' => 'Zobrazit transakce',
    'view_budgets' => 'Zobrazit rozpočty',
    'back' => 'Zpět na migrace',
];
