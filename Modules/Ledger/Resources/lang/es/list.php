<?php

declare(strict_types=1);

return [
    'page_title' => 'Transacciones',
    'heading' => 'Transacciones',

    'subtitle_searching' => 'Buscando en todo el historial',
    'subtitle_full' => 'Historial completo.',
    'subtitle_recent' => 'Transacciones recientes (últimos 90 días).',

    'currency_aria' => 'Importe mostrado',
    'currency_eur' => 'Importe liquidado',
    'currency_original' => 'Importe original',

    'show_recent' => 'Mostrar solo las recientes',
    'show_full' => 'Mostrar el historial completo',

    'empty_period' => 'No hay nada en este periodo.',

    'empty_recent_has_older' => 'Nada en los últimos 90 días. Tus movimientos anteriores siguen aquí.',

    'empty_history' => 'Aún no hay movimientos.',
    'loading_more' => 'Cargando más transacciones',
    'load_more' => 'Cargar más',

    'split_badge' => 'Desglose · :count',
    'split_expand_aria' => 'Desglosada en :count categoría — despliega para verlas|Desglosada en :count categorías — despliega para verlas',

    'chain_badge' => 'cadena',
    'chain_title' => 'Forma parte de una cadena — abre esta fila para verla',

    'table' => [
        'date' => 'Fecha',
        'counterparty' => 'Contraparte',
        'category' => 'Categoría',
        'tax' => 'Impuestos',
        'status' => 'Estado',
        'amount' => 'Importe',
    ],

    'search' => [
        'placeholder' => 'Buscar comercio, descripción, notas…',
        'placeholder_short' => 'Buscar transacciones…',
        'aria' => 'Buscar transacciones',
        'clear_all' => 'Borrar todo',
        'filters' => 'Filtros',
        'open_filters_aria' => 'Abrir filtros',
        'apply' => 'Aplicar',
        'clear' => 'Borrar',

        'count' => ':count transacción|:count transacciones',
        'matching_suffix' => 'coinciden con los filtros',
        'flow' => ':out salen / :in entran',
    ],

    'no_results' => [
        'heading' => 'No hay coincidencias',
        'remove_prompt' => 'Prueba a quitar algún filtro que pueda estar limitando los resultados:',
        'no_match_query' => 'Ninguna transacción de todo el historial coincide con «:query».',
        'no_match_filters' => 'Ninguna transacción coincide con los filtros aplicados.',
        'did_you_mean' => 'Quizá quisiste decir:',
        'account_fallback' => 'Cuenta :id',
        'category_fallback' => 'Categoría :id',
    ],

    'filter' => [
        'date' => 'Fecha',
        'account' => 'Cuenta',
        'amount' => 'Importe',
        'category' => 'Categoría',
        'date_range' => 'Intervalo de fechas',
        'from' => 'Desde',
        'to' => 'Hasta',
        'custom_range' => 'Intervalo personalizado ×',
        'after' => 'Después del :date ×',
        'before' => 'Antes del :date ×',
        'dir_both' => 'Ambos',
        'dir_in' => 'Entradas',
        'dir_out' => 'Salidas',
        'min' => 'Mín.',
        'max' => 'Máx.',
        'min_aria' => 'Importe mínimo',
        'max_aria' => 'Importe máximo',
        'after_aria' => 'Después de la fecha',
        'before_aria' => 'Antes de la fecha',
        'acct' => ':count cuenta|:count cuentas',
        'cat' => ':count categoría|:count categorías',
        'date_dialog' => 'Filtro de fecha',
        'account_dialog' => 'Filtro de cuenta',
        'amount_dialog' => 'Filtro de importe',
        'category_dialog' => 'Filtro de categoría',
        'remove_date_aria' => 'Quitar el filtro de fecha',
        'remove_account_aria' => 'Quitar el filtro de cuenta',
        'remove_amount_aria' => 'Quitar el filtro de importe',
        'remove_category_aria' => 'Quitar el filtro de categoría',

        'remove_named_aria' => 'Quitar el filtro :name',
    ],

    'date_preset' => [
        'this_month' => 'Este mes',
        'last_month' => 'El mes pasado',
        'this_year' => 'Este año',
        'last_year' => 'El año pasado',
    ],
];
