<?php

declare(strict_types=1);

return [
    'heading' => 'Pagos fijos mensuales',

    'summary' => [
        'expenses' => 'gastos',
        'income' => 'ingresos',
        'net' => 'neto',
    ],

    // The pill beside each series. It printed Direction's raw case —
    // "expense" beside "uitgaven" in the same line — because the value went
    // straight to the template. Same words CashBook labels its picker with.
    'direction' => [
        'expense' => 'Gasto',
        'income' => 'Ingreso',
    ],

    'filter_aria' => 'Filtrar los pagos fijos',
    'filter_all' => 'Todas las series',
    'filter_this_month' => 'Solo este mes',

    'empty_this_month' => 'No hay ninguna serie recurrente que venza este mes.',
    'empty_all' => 'Todavía no hay series recurrentes aprobadas.',

    'chain' => 'cadena',
    'chain_aria' => 'Financiado mediante una cadena',
    'per_month_suffix' => '/mes',

    'view_all' => 'Ver todas →',
];
