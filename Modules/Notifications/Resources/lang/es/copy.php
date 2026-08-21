<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Importación terminada',
        'receipts' => 'Nuevos recibos encontrados',
        'drift' => 'Un cargo recurrente ha cambiado',
        'forecast' => 'Se avecina un déficit de tesorería',
        'budget_nudge' => 'Presupuesto casi agotado',
        'savings_prompt' => 'Existe un plan más barato',
        'ics_statement_ready' => 'Nuevo extracto ICS disponible',
        'payment_reminder_confident' => 'Pago el :day',
        'payment_reminder_hedged' => 'Pago hacia el :day',
        'position_digest_daily' => 'Tu situación diaria',
        'position_digest_weekly' => 'Tu situación semanal',
    ],

    'body' => [
        'budget_nudge' => ':category — gastado :spent de :budget.',
        'receipts_matched' => ':count recibo asociado desde tu correo.|:count recibos asociados desde tu correo.',
        'import_finished' => ':count transacción importada.|:count transacciones importadas.',
        'drift' => 'Un cargo recurrente se ha movido :direction en :amount.',
        'forecast' => 'Tu saldo previsto baja de cero en los próximos 30 días.',
        'ics_statement_ready' => 'Descárgalo del portal de ICS y suéltalo en Beatrax para mantener al día el gasto de esta tarjeta.',
        'payment_reminder_hedged' => ':name — previsto hacia el :day, :amount.',
        'payment_reminder_confident' => ':name — vence el :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mes)',
    ],

    'drift_direction' => [
        'up' => 'al alza',
        'down' => 'a la baja',
    ],

    'digest' => [
        'nothing_notable' => 'No hay nada que requiera tu atención.',
        'flow' => 'Entradas :in, salidas :out, neto :net.',
        'over_budget' => ':amount por encima del presupuesto hasta ahora.',
        'payments_due' => ':count pago vence en este periodo.|:count pagos vencen en este periodo.',
        'shortfall' => 'Se avecina un déficit de tesorería.',
    ],
];
