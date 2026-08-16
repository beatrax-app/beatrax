<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Calendario',
        'subtitle' => 'Próximos pagos y tu saldo diario previsto.',
    ],

    'summary' => [
        'computing' => 'Actualizando la previsión…',
        'risk' => 'El saldo baja de 0 € el :date.|El saldo baja de 0 € en :count días — el primero: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Mes anterior',
        'next_month' => 'Mes siguiente',
        'accounts' => 'Cuentas',
        'popover_aria' => 'Ajustes de visualización de cuentas',
        'no_accounts' => 'No se han encontrado cuentas.',
        'col_account' => 'Cuenta',
        'col_entries' => 'Entradas',
        'col_balance' => 'Saldo',
        'show_entries_aria' => 'Mostrar las entradas de :name',
        'count_balance_aria' => 'Contar :name en el saldo',
    ],

    'empty' => [
        'heading' => 'No hay pagos próximos',
        'body' => 'Conecta una cuenta o aprueba una serie recurrente para ver tus pagos previstos en el calendario.',
        'review' => 'Revisar recurrentes →',
    ],

    'weekdays' => [
        'mon' => 'Lun',
        'tue' => 'Mar',
        'wed' => 'Mié',
        'thu' => 'Jue',
        'fri' => 'Vie',
        'sat' => 'Sáb',
        'sun' => 'Dom',
    ],

    'grid' => [
        'aria' => 'Calendario de :month',
    ],

    'cell' => [
        'entry' => 'entrada|entradas',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', saldo previsto menos :amount €',
        'aria_balance_positive' => ', saldo previsto :amount €',
        'overflow' => '+:count más',
        'paid' => 'Pagado',
        'missed' => 'Previsto — no encontrado',
    ],

    'panel' => [
        'aria' => 'Panel de detalle del día',
        'close' => 'Cerrar el panel del día',
        'start_of_day' => 'Inicio del día',
        'no_payments' => 'No hay pagos este día.',
        'date_approximate' => '~ fecha aproximada',
        'series' => '↗ serie',
        'counterparty' => '↗ contraparte',
        'end_of_day' => 'Fin del día',
    ],
];
