<?php

declare(strict_types=1);

return [
    'page_title' => 'Cadenas',
    'heading' => 'Cadenas',
    'review_link' => 'Cola de revisión →',
    'hints_link' => 'Pistas →',
    'subtitle' => 'Compras agrupadas en un solo cargo. Cada tarjeta muestra un cargo y los pagos que lo componen.',

    'empty_heading' => 'Aún no hay cadenas',
    'empty_body' => 'Importa algunos extractos (banco, PayPal, tarjeta) y el resolutor mostrará aquí las cadenas entre cuentas automáticamente.',

    'no_counterparty' => '(sin contraparte)',
    'leg_count' => ':count pago|:count pagos',
    'legs_more' => '+ :count más',
    'state_aria' => 'Estado: :state',

    'state' => [
        'candidate' => 'Candidata',
        'confirmed' => 'Confirmada',
        'rejected' => 'Rechazada',
    ],

    'kind' => [
        'paypal_funding' => 'Financiación con PayPal',
        'ics_bulk_settle' => 'Liquidación iDEAL agrupada',
        'funded_by_card_hint' => 'Financiado con tarjeta (pista)',
        'refund_of_hint' => 'Reembolso (pista)',
    ],
];
