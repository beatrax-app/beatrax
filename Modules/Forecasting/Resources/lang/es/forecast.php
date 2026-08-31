<?php

declare(strict_types=1);

return [
    'heading' => 'Previsión',
    'page_title' => 'Previsión',
    'subtitle' => 'Hacia dónde va tu saldo — en los próximos 30 a 365 días.',
    'adjust_buffers' => 'Ajustar reservas',

    'empty_heading' => 'Aún no hay datos de previsión',
    'empty_body' => 'Conecta una cuenta o aprueba una serie recurrente para ver tu saldo proyectado en las próximas semanas.',
    'empty_start' => 'Empieza por',
    'empty_import_link' => 'importar un extracto',
    'empty_or' => 'o',
    'empty_recurring_link' => 'revisar los patrones recurrentes',

    'account_tablist' => 'Cuenta',
    'all_accounts' => 'Todas las cuentas',

    'horizon_label' => 'Horizonte de la previsión',
    'n_days' => ':days día|:days días',

    'view_by_funder' => 'Ver por financiador',
    'view_by_funder_hint' => 'Agrupa las series resueltas por cadena en la cuenta que las paga realmente.',

    'scenario_group' => 'Escenario',
    'baseline' => 'Referencia',
    'scenario_word' => 'Escenario',
    'new_scenario' => '+ Nuevo escenario',
    'scenario_name_placeholder' => 'Nombre del escenario',
    'new_scenario_aria' => 'Nombre del nuevo escenario',
    'create_scenario' => 'Crear escenario',
    'cancel' => 'Cancelar',

    'aggregate_subtitle' => 'Saldo combinado de todas las cuentas, proyectado en el próximo :days día.|Saldo combinado de todas las cuentas, proyectado en los próximos :days días.',

    'today' => 'hoy',
    'on_day' => 'en el día',

    'edit_buffer_aria' => 'Editar la reserva mínima de :name',
    'buffer_not_set' => 'Reserva: sin definir',
    'buffer_set' => 'Reserva: :amount',

    'shortfall' => 'El déficit empieza el :date — :amount por debajo de tu reserva de :buffer',

    'compared_against_baseline' => 'Comparado con la referencia de arriba',

    'run_failed' => 'No se pudo calcular esta proyección. La línea de abajo solo muestra lo que ya está contabilizado.',

    'scenario_editor_aria' => 'Editor de escenarios',
    'series_confidence' => 'Confianza de las series',
    'no_series_contribute' => 'Todavía no hay series que aporten a la previsión de esta cuenta.',

    'net_diff' => 'Diferencia neta',

    'net_diff_unknown' => 'Aún no calculado para este horizonte.',
    'net_diff_section_aria' => 'Diferencia neta entre la referencia y el escenario en los horizontes de 30 / 60 / 90 días',
    'net_diff_delta_aria' => 'Diferencia neta en el día :day: :value, el escenario es :state',
    'better_than_baseline' => 'mejor que la referencia',
    'worse_than_baseline' => 'peor que la referencia',
    'equal_to_baseline' => 'igual que la referencia',
    'at_day' => 'en el día :day',

    'updating' => 'Actualizando',
    'chart_noscript' => 'El gráfico requiere JavaScript. El rango abarca :days día.|El gráfico requiere JavaScript. El rango abarca :days días.',
    'total_balance' => 'Saldo total',
    'projection_range' => 'Rango de proyección',
    'point_estimate' => 'Estimación puntual',

    'per_month_suffix' => '/mes',
    'confidence_chip_aria' => ':name, confianza :confidence — el rango de proyección es el :percent por ciento de la estimación puntual',

    'highlights_title' => 'Puntos clave de la previsión',
    'highlights_shortfall_aria' => ':count ventana de déficit activa en los próximos :days días|:count ventanas de déficit activas en los próximos :days días',
    'on_date_suffix' => ' el :date',
    'shortfall_window' => ':count ventana de déficit activa|:count ventanas de déficit activas',
    'lowest_in_30_label' => 'Mínimo en 30 días',
    'next_ics' => 'Próxima liquidación ICS: :amount el :date',
    'ics_overdue' => 'Liquidación ICS vencida: :amount, vencía el :date',

    'stale_run' => 'Proyectado desde el :date — sin actualizar desde entonces.',

    'confidence' => [
        'high' => 'Alta',
        'medium' => 'Media',
        'low' => 'Baja',
    ],

    'errors' => [
        'amount_required' => 'El importe es obligatorio.',
        'amount_decimals' => 'El importe debe ser un número con como máximo :decimals decimal.|El importe debe ser un número con como máximo :decimals decimales.',
        'amount_whole' => 'El importe debe ser un número entero: esta moneda no tiene unidad menor.',
        'amount_non_negative' => 'El importe debe ser cero o positivo.',
        'amount_non_zero' => 'El importe no puede ser cero.',
        'field_required' => 'El campo :field es obligatorio.',
    ],
];
