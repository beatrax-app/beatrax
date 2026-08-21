<?php

declare(strict_types=1);

return [
    'page_title' => 'Importazione completata',

    'heading_complete' => 'Importazione completata',
    'heading_update' => 'Aggiornamento applicato',

    'summary_line' => 'Importate :categories, :budget_months e :transactions.',
    'summary_categories' => ':count categoria|:count categorie',
    'summary_budget_months' => ':count mese di budget|:count mesi di budget',
    'summary_transactions' => ':count transazione|:count transazioni',
    'summary_attention' => ':count elemento richiede ancora attenzione — vedi sotto.|:count elementi richiedono ancora attenzione — vedi sotto.',

    'stats' => [
        'category' => 'Categorie',
        'account' => 'Conti',
        'payee' => 'Controparti',
        'transaction' => 'Transazioni',
        'budget' => 'Mesi di budget',
    ],

    'groups' => [
        'category' => 'Ancora non importate — categorie',
        'payee' => 'Ancora non importati — beneficiari',
        'extra' => 'Non importato',
        'conflict' => 'Richiede una tua decisione',
    ],

    'view_transactions' => 'Vedi le transazioni',
    'view_budgets' => 'Vedi i budget',
    'back' => 'Torna alle migrazioni',
];
