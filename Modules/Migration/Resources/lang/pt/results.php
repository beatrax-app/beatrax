<?php

declare(strict_types=1);

return [
    'page_title' => 'Importação concluída',

    'heading_complete' => 'Importação concluída',
    'heading_update' => 'Atualização aplicada',

    'summary_line' => 'Importadas :categories, :budget_months e :transactions.',
    'summary_categories' => ':count categoria|:count categorias',
    'summary_budget_months' => ':count mês de orçamento|:count meses de orçamento',
    'summary_transactions' => ':count transação|:count transações',
    'summary_attention' => ':count item ainda precisa de atenção — vê em baixo.|:count itens ainda precisam de atenção — vê em baixo.',

    'stats' => [
        'category' => 'Categorias',
        'account' => 'Contas',
        'payee' => 'Contrapartes',
        'transaction' => 'Transações',
        'budget' => 'Meses de orçamento',
    ],

    'groups' => [
        'category' => 'Ainda por importar — categorias',
        'payee' => 'Ainda por importar — contrapartes',
        'extra' => 'Não importado',
        'conflict' => 'Precisa da tua decisão',
    ],

    'view_transactions' => 'Ver transações',
    'view_budgets' => 'Ver orçamentos',
    'back' => 'Voltar às migrações',
];
