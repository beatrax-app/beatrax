<?php

declare(strict_types=1);

return [
    'page_title' => 'Pistas de cadenas',
    'heading' => 'Pistas',
    'back_to_review' => '← Volver a la cola de revisión',
    'subtitle' => 'Candidatos que un comparador emitió sin una contraparte coincidente. Una pista de liquidación se resuelve sola en cuanto llegan los cargos que faltaban; el resto permanece hasta que la descartes aquí.',

    'empty_heading' => 'No hay pistas que revisar',
    'empty_body' => 'Cuando el emparejador detecte una cadena que no ha podido resolver automáticamente, aparecerá aquí.',

    'no_counterparty' => '(sin contraparte)',
    'unknown_account' => '(cuenta desconocida)',

    'dismiss' => 'Descartar',
    'dismiss_aria' => 'Descartar la pista :id',
    'dismissed' => 'Pista descartada.',

    'kind' => [
        'ics_bulk_settle' => 'Liquidación iDEAL agrupada (fuera de tolerancia)',
        'funded_by_card_hint' => 'Financiado con tarjeta (pista)',
        'refund_of_hint' => 'Reembolso (pista)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerancia: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'dentro del margen fijo',
            'percent_2' => 'dentro del margen porcentual',
            'exceeded' => 'fuera del margen',
            'refund_after_close' => 'reembolso tras el cierre',
        ],
        'delta_overpaid' => 'Pagado de más: :amount',
        'delta_underpaid' => 'Falta: :amount',
        'delta_balanced' => 'Cuadra exactamente',
        'covered' => 'Transacciones cubiertas: :count',
        'statement' => 'Extracto de tarjeta n.º :id',
        'card_last4' => 'Tarjeta terminada en :last4',
        'original_reference' => 'Referencia del pedido original: :reference',
    ],
];
