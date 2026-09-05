<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importación terminada',
        'receipts' => 'Nuevos recibos encontrados',
        'manual_entry' => 'Libro de caja actualizado',
        'migration_finished' => 'Migración terminada',
        'drift' => 'Un cargo recurrente ha cambiado',
        'forecast' => 'Se avecina un déficit de tesorería',
        'budget_nudge' => 'Presupuesto casi agotado',
        'budget_nudge_spent' => 'Presupuesto agotado',
        'budget_nudge_over' => 'Presupuesto superado',
        'savings_prompt' => 'Un sitio donde podrías ahorrar',
        'ics_statement_ready' => 'Nuevo extracto ICS disponible',
        'payment_reminder_confident' => 'Pago el :day (:date)',
        'payment_reminder_hedged' => 'Pago hacia el :day (:date)',
        'position_digest_daily' => 'Tu situación diaria',
        'position_digest_weekly' => 'Tu situación semanal',
    ],

    'body' => [
        'budget_nudge' => ':category — gastado :spent de :budget.',
        'receipts_matched' => ':count recibo asociado desde tu correo.|:count recibos asociados desde tu correo.',
        'import_finished' => ':count transacción importada.|:count transacciones importadas.',
        'manual_entry' => ':count entrada añadida a mano.|:count entradas añadidas a mano.',
        'migration_finished' => 'Tu presupuesto se ha trasladado, incluida :count transacción.|Tu presupuesto se ha trasladado, incluidas :count transacciones.',
        'drift' => 'Un cargo recurrente se ha movido :direction en :amount.',
        'forecast' => 'Tu saldo previsto baja de cero el :date.',
        'forecast_buffer' => 'Tu saldo previsto baja de tu reserva de :buffer el :date.',
        'ics_statement_ready' => 'Descárgalo del portal de ICS y suéltalo en Beatrax para mantener al día el gasto de esta tarjeta.',
        'payment_reminder_hedged' => ':name — previsto hacia el :day (:date), :amount.',
        'payment_reminder_confident' => ':name — vence el :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'al alza',
        'down' => 'a la baja',
    ],

    'digest' => [
        'nothing_notable' => 'No hay nada que requiera tu atención.',
        'flow' => 'Entradas :in, salidas :out, neto :net.',
        'net_worth' => 'Patrimonio neto :amount.',
        'over_budget' => ':amount por encima del presupuesto hasta ahora.',
        'payments_due' => ':count pago vence en este periodo.|:count pagos vencen en este periodo.',
        'shortfall' => 'Se avecina un déficit de tesorería.',
        'forecast_not_run' => 'Todavía no se ha ejecutado ninguna previsión de tesorería.',
    ],
];
