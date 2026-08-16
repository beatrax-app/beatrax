<?php

declare(strict_types=1);

return [
    'page_title' => 'Kedjor',
    'heading' => 'Kedjor',
    'review_link' => 'Granskningskö →',
    'hints_link' => 'Ledtrådar →',
    'subtitle' => 'Alla kedjor som kedjelösaren har hittat. Klicka på radens finansierade transaktion för att öppna kedjepanelen med hela förgreningen.',

    'empty_heading' => 'Inga kedjor ännu',
    'empty_body' => 'Importera några kontoutdrag (bank, PayPal, kort) så visar kedjelösaren kedjor mellan konton här automatiskt.',

    'no_counterparty' => '(ingen motpart)',
    'open_from_row' => 'Öppna från-raden',
    'open_to_row' => 'Öppna till-raden',
    'leg_count' => '1 betalning|:count betalningar',
    'state_aria' => 'Status: :state',

    'kind' => [
        'paypal_funding' => 'PayPal-finansiering',
        'ics_bulk_settle' => 'Samlad iDEAL-avräkning',
        'funded_by_card_hint' => 'Finansierad med kort (ledtråd)',
        'refund_of_hint' => 'Återbetalning (ledtråd)',
    ],
];
