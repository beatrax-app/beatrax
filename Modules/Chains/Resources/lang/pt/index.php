<?php

declare(strict_types=1);

return [
    'page_title' => 'Cadeias',
    'heading' => 'Cadeias',
    'review_link' => 'Fila de revisão →',
    'hints_link' => 'Pistas →',
    'subtitle' => 'Compras agrupadas numa única cobrança. Cada cartão mostra uma cobrança e os pagamentos que a compõem.',

    'empty_heading' => 'Ainda não há cadeias',
    'empty_body' => 'Importa alguns extratos (banco, PayPal, cartão) e o resolvedor mostra aqui automaticamente as cadeias entre contas.',

    'no_counterparty' => '(sem contraparte)',
    'leg_count' => ':count pagamento|:count pagamentos',
    'legs_more' => '+ mais :count',
    'state_aria' => 'Estado: :state',

    'state' => [
        'candidate' => 'Candidata',
        'confirmed' => 'Confirmada',
        'rejected' => 'Rejeitada',
    ],

    'kind' => [
        'paypal_funding' => 'Financiamento por PayPal',
        'ics_bulk_settle' => 'Liquidação iDEAL agrupada',
        'funded_by_card_hint' => 'Financiado por cartão (pista)',
        'refund_of_hint' => 'Reembolso (pista)',
    ],
];
