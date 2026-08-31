<?php

declare(strict_types=1);

return [
    'page_title' => 'Panel',
    'subtitle' => 'Este periodo de un vistazo.',

    'previous_period' => 'Periodo anterior',
    'today' => 'Hoy',
    'next_period' => 'Periodo siguiente',

    'totals_aria' => 'Totales de este periodo',
    'totals_aria_currency' => 'Totales de este periodo — :currency',
    'in' => 'Entradas',
    'out' => 'Salidas',
    'net' => 'Neto',

    'status_tiles_aria' => 'Tarjetas de estado',
    'email_scan_health' => 'Estado del análisis de correo — :count bandeja de entrada conectada|Estado del análisis de correo — :count bandejas de entrada conectadas',

    'top_spending' => 'Principales gastos',
    'no_expenses' => 'Aún no hay gastos categorizados.',
    'top_spending_refunded' => 'Fuera del ranking — :amount volvió',

    'recent_transactions' => 'Transacciones recientes',
    'view_all' => 'Ver todo',
    'nothing_period' => 'No hay nada en este periodo.',
    'th_date' => 'Fecha',
    'th_counterparty' => 'Contraparte',
    'th_category' => 'Categoría',
    'th_amount' => 'Importe',
    'uncategorized' => 'Sin categorizar',

    'jump_to_records' => [
        'body' => 'No hay nada en este periodo. Tus movimientos más recientes siguen aquí.',
        'action' => 'Mostrar :period',
    ],

    'reauth' => [
        'title' => 'Hay una bandeja de entrada que debes volver a conectar.',
        'body' => 'Se ha cerrado la sesión de una o varias bandejas de entrada — Beatrax no puede analizarlas hasta que las vuelvas a conectar.',
        'link' => 'Ir a Bandejas de entrada',
        'dismiss' => 'Descartar',
    ],

    'failed_chain' => [
        'title' => 'La resolución de cadenas ha fallado.',
        'body' => 'Uno o varios trabajos de resolución de cadenas han dado error.',
        'link' => 'Abrir el inspector de colas',
    ],
];
