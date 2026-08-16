<?php

declare(strict_types=1);

return [
    'title' => 'Informes',
    'page_title' => 'Informes · Beatrax',
    'subtitle' => 'Compón un informe a partir de tu libro mayor.',
    'controls_aria' => 'Controles del informe',
    'result_aria' => 'Resultado del informe',
    'dismiss' => 'Descartar',

    'metric' => [
        'heading' => 'Métrica',
        'spend' => 'Gasto',
        'income' => 'Ingresos',
        'net' => 'Neto',
        'net_worth' => 'Patrimonio neto',
        'fallback' => 'Importe',
    ],

    'group_by' => 'Agrupar por',

    'dimension' => [
        'category' => 'Categoría',
        'time_bucket' => 'Intervalo de tiempo',
        'counterparty' => 'Contraparte',
        'account' => 'Cuenta',
    ],

    'period' => [
        'heading' => 'Periodo',
        'this_month' => 'Este mes',
        'last_3_months' => 'Últimos 3 meses',
        'last_6_months' => 'Últimos 6 meses',
        'last_12_months' => 'Últimos 12 meses',
        'ytd' => 'Lo que va de año',
        'this_year' => 'Este año',
        'custom' => 'Intervalo personalizado',
        'from' => 'Desde',
        'to' => 'Hasta',
    ],

    'currency' => [
        'heading' => 'Moneda',
        'aria' => 'Modo de moneda',
        'base' => 'Base',
        'original' => 'Original',
    ],

    'granularity' => [
        'heading' => 'Granularidad',
        'aria' => 'Granularidad temporal',
        'monthly' => 'Mensual',
        'weekly' => 'Semanal',
    ],

    'filters' => [
        'heading' => 'Filtros',
    ],

    'compare' => 'Comparar con el periodo anterior',

    'viz' => [
        'heading' => 'Visualización',
        'table' => 'Tabla',
        'bar' => 'Barras',
        'line' => 'Líneas',
        'donut' => 'Anillo',
    ],

    'actions' => [
        'update_report' => 'Actualizar informe',
        'save_report' => 'Guardar informe',
        'report_name' => 'Nombre del informe',
        'update' => 'Actualizar',
        'save' => 'Guardar',
        'cancel' => 'Cancelar',
        'export_csv' => 'Exportar CSV',
    ],

    'updating' => '… Actualizando',

    'empty' => [
        'heading' => 'No hay nada que mostrar para esta selección',
        'body' => 'Prueba a ampliar el intervalo de fechas o a quitar un filtro.',
    ],

    'total_prefix' => 'Total',
    'total' => 'Total',
    'vs_previous' => 'frente al periodo anterior',
    'view_transactions' => 'Ver transacciones',

    'fx_excluded' => ':count cuenta sin convertir — no hay tipo de cambio disponible|:count cuentas sin convertir — no hay tipo de cambio disponible',

    'group_header' => [
        'category' => 'Categoría',
        'counterparty' => 'Contraparte',
        'account' => 'Cuenta',
        'month' => 'Mes',
        'default' => 'Grupo',
    ],

    'chart' => [
        'bar_title' => 'Pulsa una barra para ver sus transacciones',
        'line_title' => 'Pulsa un punto para ver sus transacciones',
        'donut_title' => 'Pulsa un segmento para ver sus transacciones',
    ],

    'flash' => [
        'saved' => 'Informe guardado.',
        'updated' => 'Informe actualizado.',
    ],

    'filter' => [
        'account' => 'Cuenta',
        'account_count' => ':count cuenta|:count cuentas',
        'remove_account' => 'Quitar el filtro de cuenta',
        'account_dialog' => 'Filtro de cuenta',

        'category' => 'Categoría',
        'category_count' => ':count categoría|:count categorías',
        'remove_category' => 'Quitar el filtro de categoría',
        'category_dialog' => 'Filtro de categoría',

        'counterparty' => 'Contraparte',
        'counterparty_count' => ':count contraparte|:count contrapartes',
        'remove_counterparty' => 'Quitar el filtro de contraparte',
        'counterparty_dialog' => 'Filtro de contraparte',

        'amount' => 'Importe',
        'remove_amount' => 'Quitar el filtro de importe',
        'amount_dialog' => 'Filtro de importe',
        'dir_both' => 'Ambos',
        'dir_in' => 'Entradas',
        'dir_out' => 'Salidas',
        'min' => 'Mín',
        'max' => 'Máx',
        'min_aria' => 'Importe mínimo',
        'max_aria' => 'Importe máximo',
    ],
];
