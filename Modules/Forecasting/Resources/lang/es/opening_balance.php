<?php

declare(strict_types=1);

return [
    'help_paypal' => 'Las exportaciones de PayPal no incluyen líneas de saldo, así que ponlo a mano.',
    'help_asn' => 'Se ancla automáticamente a tu último extracto. Sobrescríbelo solo si sabes que el saldo real es distinto.',
    'help_default' => 'Sobrescríbelo solo si sabes que el saldo real actual es distinto del que calcula Beatrax.',

    'legend' => 'Saldo de apertura de la previsión de :name',
    'opening_label' => 'Saldo de apertura',
    'opening_placeholder' => 'p. ej. 1.250,00',
    'as_of_label' => 'Saldo de apertura a fecha de',
    'as_of_help' => 'La fecha en la que la cifra de arriba es correcta.',

    'divergence' => 'Esto se aleja más de 500 € del saldo que Beatrax calcula a partir de las transacciones importadas. ¿Seguro que es correcto?',
    'use_beatrax' => 'Usar la cifra de Beatrax',
    'use_mine' => 'Usar mi cifra',

    'save' => 'Guardar el saldo de apertura',
    'saved' => 'Guardado.',

    'toast' => [
        'updated' => 'Saldo de apertura actualizado.',
    ],

    'errors' => [
        'invalid_number' => 'El saldo de apertura debe ser un número válido.',
        'date_required' => 'Elige la fecha a la que se aplica este saldo de apertura.',
        'date_invalid' => 'La fecha del saldo de apertura debe ser una fecha ISO válida (YYYY-MM-DD).',
        'date_future' => 'La fecha del saldo de apertura no puede estar en el futuro.',
    ],
];
