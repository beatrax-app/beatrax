<?php

declare(strict_types=1);

return [
    'heading' => 'Pagamentos mensais fixos',

    'summary' => [
        'expenses' => 'despesas',
        'income' => 'receitas',
        'net' => 'líquido',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Despesa',
        'income' => 'Receita',
    ],

    'filter_aria' => 'Filtrar pagamentos fixos',
    'filter_all' => 'Todas as séries',
    'filter_this_month' => 'Só este mês',

    'empty_this_month' => 'Não há séries recorrentes a vencer este mês.',
    'empty_all' => 'Ainda não há séries recorrentes aprovadas.',

    'chain' => 'cadeia',
    'chain_aria' => 'Financiado através de uma cadeia',
    'per_month_suffix' => '/mês',

    'view_all' => 'Ver tudo →',
];
