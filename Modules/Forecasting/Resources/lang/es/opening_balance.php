<?php

declare(strict_types=1);

return [
    'help_paypal' => 'Las exportaciones de PayPal no incluyen líneas de saldo, así que ponlo a mano.',
    'help_default' => 'Sobrescríbelo solo si sabes que el saldo real actual es distinto del que calcula Beatrax.',

    'legend' => 'Saldo de apertura de la previsión de :name',
    'opening_label' => 'Saldo de apertura',
    'opening_placeholder' => 'p. ej. :amount',
    'as_of_label' => 'Saldo de apertura a fecha de',
    'as_of_help' => 'La fecha en la que la cifra de arriba es correcta.',

    'divergence' => 'Esto se aleja más de :threshold del saldo que Beatrax calcula a partir de las transacciones importadas. ¿Seguro que es correcto?',
    'computed_is' => 'Beatrax calcula :amount.',
    'use_beatrax' => 'Usar la cifra de Beatrax',
    'use_mine' => 'Usar mi cifra',

    'save' => 'Guardar el saldo de apertura',
    'remove' => 'Eliminar el saldo de apertura',
    'saved' => 'Guardado.',
    'removed' => 'Eliminado.',

    'toast' => [
        'updated' => 'Saldo de apertura actualizado.',
        'removed' => 'Saldo de apertura eliminado.',
    ],

    'errors' => [
        'invalid_number' => 'El saldo de apertura debe ser un número válido.',
        'date_required' => 'Elige la fecha a la que se aplica este saldo de apertura.',
        'date_invalid' => 'La fecha del saldo de apertura debe ser una fecha ISO válida (YYYY-MM-DD).',
        'date_future' => 'La fecha del saldo de apertura no puede estar en el futuro.',
    ],
];
