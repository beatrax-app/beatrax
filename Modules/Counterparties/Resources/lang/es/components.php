<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Tipo de contraparte: :type',
        'merchant' => 'Comercio',
        'personal' => 'Personal',
        'bank' => 'Banco',
        'government' => 'Administración',
        'self' => 'Propia',
        'unknown' => 'Desconocida',
    ],

    'filter_chips' => [
        'aria' => 'Filtrar por tipo',
        'all' => 'Todas',
        'merchant' => 'Comercios',
        'personal' => 'Personales',
        'bank' => 'Bancos',
        'government' => 'Administración',
        'self' => 'Propias',
        'unknown' => 'Desconocidas',
    ],

    'cp_card' => [
        'aria' => 'Contraparte: :name',
        'recent_aria' => 'Actividad reciente',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Cadena de financiación: ',
        'join' => ' a ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN oculto — pulsa Mostrar IBAN para verlo',
        'show' => 'Mostrar IBAN',
        'hide' => 'Ocultar IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Aviso de privacidad para contactos personales',
        'body' => '🔒 Este es un contacto personal. El IBAN y los datos personales están ocultos por defecto y nunca se comparten en las exportaciones.',
    ],

    'self_stub' => [
        'aria' => 'No es una contraparte real',
        'heading' => 'Esto no es realmente una contraparte',

        'body_rest_html' => ' aparece aquí porque figura en tus transacciones como el tramo de financiación entre cuentas. Pero es <strong>tu propia cuenta</strong>, no alguien con quien operas.',
        'body2' => 'Abre la vista de la cuenta para ver el saldo, los extractos y todo el historial de transacciones.',
        'open_cta' => 'Abrir la vista de cuenta de :name →',
        'hide_cta' => 'Ocultar de esta lista',
        'recent_legs' => 'Tramos recientes entre cuentas',
    ],
];
