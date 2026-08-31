<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Escribe para buscar vistas, comandos y acciones. Pulsa Esc para cerrar.',
    'search_aria' => 'Escribe para buscar vistas, comandos y acciones',
    'dialog_aria' => 'Paleta de comandos',
    'token_suggest_aria' => 'Sugerencias de tokens',
    'rail_view' => 'Vista',
    'rail_dev' => 'Dev',
    'rail_action' => 'Acción',
    'rail_recent' => 'Reciente',
    'no_recent' => 'Aún no hay selecciones recientes.',
    'section_transactions' => 'Transacciones',
    'section_counterparties' => 'Contrapartes',
    'section_categories' => 'Categorías',
    'section_goals_recurring' => 'Objetivos y recurrentes',
    'no_name' => '(sin nombre)',
    'see_all' => 'Ver :count resultado →|Ver los :count resultados →',
    'no_transactions' => 'Ninguna transacción coincide con ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'contraparte',
    'source_category' => 'categoría',
    'results_aria' => 'Resultados',
    'no_results' => 'Sin resultados.',
    'foot_navigate' => 'navegar',
    'foot_select' => 'seleccionar',
    'foot_close' => 'cerrar',
    'close_aria' => 'Cerrar la búsqueda',
    'close_caption' => 'Cerrar',
    'foot_try' => 'Prueba',
    'results' => ':count resultado|:count resultados',

    'action' => [
        'run_import' => ['label' => 'Ejecutar una importación', 'hint' => 'Abrir el asistente de importación'],
        'scan_email' => ['label' => 'Analizar el correo ahora', 'hint' => 'Ejecutar ahora mismo la sincronización de la bandeja de entrada'],
        'open_profile' => ['label' => 'Abrir el perfil', 'hint' => 'Ajustes — cuenta y preferencias'],
        'toggle_theme' => ['label' => 'Cambiar el tema', 'hint' => 'Alternar entre el tema claro y el oscuro'],
    ],

    'run_command' => 'Ejecutar :command',

    'nav' => [
        'overview' => ['label' => 'Resumen de desarrollo', 'hint' => 'Paneles del sistema + ejecuciones recientes'],
        'artisan' => ['label' => 'Runner de Artisan', 'hint' => 'Ejecutar comandos autorizados'],
        'audit' => ['label' => 'Registro de auditoría de desarrollo', 'hint' => 'Cada acción del modo desarrollador'],
        'logs' => ['label' => 'Visor de registros', 'hint' => 'Flujo en directo de laravel-*.log'],
        'queue' => ['label' => 'Inspector de colas', 'hint' => 'Pendientes / fallidos / lotes'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Sondas del sistema'],
        'sql' => ['label' => 'Panel SQL', 'hint' => 'Explorador solo con SELECT'],
        'system' => ['label' => 'Instantánea del sistema', 'hint' => 'Entorno + rutas + configuración'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Panel de colas integrado'],
        'sync_health' => ['label' => 'Estado de sincronización', 'hint' => 'Operaciones de fusión en cuarentena u omitidas'],
    ],
];
