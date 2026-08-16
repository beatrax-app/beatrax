<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 SALDO INICIAL',
    'confirmed_aria' => 'confirmado',
    'on_date' => 'el :date',

    'detected_h3' => 'Hemos detectado que tu :label empezó en',
    'confirm' => 'Confirmar',
    'edit' => 'Editar',

    'conflict_h3' => 'Hemos visto dos valores para esta cuenta: ¿cuál es el correcto?',
    'conflict_legend' => 'Elige un saldo inicial',
    'conflict_from' => 'De :source:',
    'conflict_helper' => 'Por defecto tomamos la fecha más antigua. Elige el valor correcto o edítalo a mano.',
    'edit_manually' => 'Editar a mano',

    'editing_h3' => 'Edita el saldo inicial de tu :label',
    'input_label' => 'SALDO INICIAL',
    'minor_units' => '(unidades menores)',
    'on_date_label' => 'EN LA FECHA',
    'cancel' => 'Cancelar',
    'save' => 'Guardar',

    'change' => 'Cambiar',

    'manual_h3' => 'Introduce a mano el saldo inicial de tu :label',
    'manual_lede' => 'No hemos podido detectar automáticamente un saldo inicial para esta cuenta. Introduce uno a mano u omite este paso.',

    'unknown_state' => 'Estado de tarjeta desconocido. Recarga el asistente.',

    'errors' => [
        'account_not_set' => 'No se ha definido la cuenta. Recarga el asistente.',
        'invalid_amount' => 'Introduce un importe válido.',
        'amount_range' => 'Introduce un importe entre -10 M€ y 10 M€.',
        'pick_date' => 'Elige una fecha.',
        'pick_valid_date' => 'Elige una fecha válida.',
        'future_date' => 'La fecha del saldo inicial no puede estar en el futuro.',
        'date_warning' => 'Es posterior a tu primera transacción importada (:date). Tu panel puede mostrar transacciones anteriores a esta fecha.',
    ],
];
