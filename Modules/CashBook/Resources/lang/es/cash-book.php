<?php

declare(strict_types=1);

return [
    'page_title' => 'Libro de caja',
    'heading' => 'Libro de caja',
    'intro' => 'Registra a mano el efectivo y otros gastos fuera del banco. Las entradas manuales van al mismo libro mayor que tus importaciones: se categorizan, se asocian a una contraparte, se detectan como recurrentes y cuentan para tu mes.',

    'direction' => 'Sentido',
    'expense' => 'Gasto',
    'income' => 'Ingreso',

    'amount' => 'Importe (:symbol)',
    'date' => 'Fecha',
    'counterparty' => 'Contraparte',
    'counterparty_placeholder' => 'p. ej. Panadería',
    'category' => 'Categoría',
    'optional' => '(opcional)',
    'uncategorized' => 'Sin categoría',
    'note' => 'Nota',

    'add_entry' => 'Añadir entrada',
    'manual_entries' => 'Entradas manuales',
    'no_entries' => 'Aún no hay entradas manuales.',
    'delete_entry' => 'Eliminar entrada',
    'delete_entry_caption' => 'Eliminar',
    'delete' => 'Eliminar',
    'delete_confirm' => '¿Eliminar esta entrada?',
    'delete_keep' => 'Conservar',

    'errors' => [
        'amount_positive' => 'Introduce un importe mayor que cero.',
        'amount_too_large' => 'Ese importe es demasiado grande. Revisa las cifras.',
        'amount_unreadable' => 'No se pudo leer el importe. Introdúcelo con :decimals decimal como máximo, por ejemplo :example.|No se pudo leer el importe. Introdúcelo con :decimals decimales como máximo, por ejemplo :example.',
        'amount_unreadable_whole' => 'No se pudo leer el importe. Esta moneda no tiene decimales, así que introduce un número entero, por ejemplo :example.',
        'invalid_date' => 'Introduce una fecha válida.',
        'not_recorded' => 'La entrada no se registró. Intenta añadirla de nuevo.',
    ],

    'toast' => [
        'added' => 'Entrada de caja añadida.',
        'removed' => 'Entrada de caja eliminada.',
        'reconciled_locked' => 'Esta transacción está conciliada. Anula la conciliación para poder modificarla.',
    ],
];
