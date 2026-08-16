<?php

declare(strict_types=1);

return [
    'page_title' => 'Pistas de cadenas',
    'heading' => 'Pistas',
    'back_to_review' => '← Volver a la cola de revisión',
    'subtitle' => 'Candidatos que el emparejador ha propuesto sin una transacción con la que encajar. Cada pista o bien se resuelve sola en la siguiente pasada de cadenas, o puedes descartarla aquí cuando decidas que no va a resolverse.',

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
];
