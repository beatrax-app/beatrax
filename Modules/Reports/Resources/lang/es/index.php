<?php

declare(strict_types=1);

return [
    'title' => 'Informes',
    'page_title' => 'Informes · Beatrax',
    'saved_report' => ':count informe guardado|:count informes guardados',
    'pinned_count' => 'fijados',
    'dismiss' => 'Descartar',

    'build_new' => 'Crear un informe nuevo',
    'view_mode_aria' => 'Modo de vista',
    'cards' => 'Tarjetas',
    'list' => 'Lista',

    'empty' => [
        'heading' => 'Todavía no hay informes guardados',
        'body' => 'Crea uno aquí abajo y guárdalo para verlo aquí.',
        'cta' => 'Crea tu primer informe →',
    ],

    'pin' => [
        'pinned_aria' => 'Fijado — quitar del panel',
        'pin_aria' => 'Fijar — fijar en el panel',
        'pinned_title' => 'Fijado',
        'pin_title' => 'Fijar en el panel',
        'pinned_label' => 'Fijado',
        'pin_label' => 'Fijar',
    ],

    'open' => 'Abrir',
    'edit' => 'Editar',

    'delete_confirm' => '¿Eliminar «:name»?',
    'delete_report' => 'Eliminar el informe',
    'cancel' => 'Cancelar',
    'delete' => 'Eliminar',
    'delete_aria' => 'Eliminar :name',

    'col' => [
        'name' => 'Nombre',
        'summary' => 'Resumen',
        'pinned' => 'Fijado',
        'actions' => 'Acciones',
    ],

    'flash' => [
        'not_found' => 'Informe no encontrado (puede que se haya eliminado en otra pestaña).',
        'deleted' => 'Informe eliminado.',
    ],
    'pin_cap' => 'Puedes fijar hasta 3 informes. Quita uno para añadir este.',

    'summary' => [
        'metric' => [
            'spend' => 'Gasto',
            'income' => 'Ingresos',
            'net' => 'Neto',
            'net_worth' => 'Patrimonio neto',
            'fallback' => 'Importe',
        ],
        'dimension' => [
            'category' => 'categoría',
            'time_bucket' => 'intervalo de tiempo',
            'counterparty' => 'contraparte',
            'account' => 'cuenta',
            'fallback' => 'categoría',
        ],
        'period' => [
            'this_month' => 'Este mes',
            'last_3_months' => 'Últimos 3 meses',
            'last_6_months' => 'Últimos 6 meses',
            'last_12_months' => 'Últimos 12 meses',
            'ytd' => 'Lo que va de año',
            'this_year' => 'Este año',
            'custom' => 'Intervalo personalizado',
        ],
        'with_dimension' => ':metric · por :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
