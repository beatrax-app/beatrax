<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'importe',
            'currency' => 'moneda',
            'description' => 'descripción',
            'counterparty_name' => 'nombre del comercio',
            'default' => 'valor',
        ],
        'heading_cleaner' => 'Un recibo por correo tiene un valor más claro en el campo :field',
        'heading_different' => 'Un recibo por correo registra un valor distinto en el campo :field',
        'title' => 'El recibo y el extracto no coinciden.',
        'body' => ':heading (“:receipt”) que el extracto (“:statement”). ¿Quieres que Beatrax dé preferencia a los recibos en los próximos conflictos?',
        'use_receipt' => 'Usar el recibo',
        'keep_statement' => 'Mantener el extracto',
    ],
];
