<?php

declare(strict_types=1);

return [
    'page_title' => 'Catene',
    'heading' => 'Catene',
    'review_link' => 'Coda di revisione →',
    'hints_link' => 'Suggerimenti →',
    'subtitle' => 'Acquisti raggruppati in un unico addebito. Ogni scheda mostra un addebito e i pagamenti che lo hanno alimentato.',

    'empty_heading' => 'Ancora nessuna catena',
    'empty_body' => 'Importa qualche estratto conto (banca, PayPal, carta) e il risolutore farà emergere qui automaticamente le catene tra conti.',

    'no_counterparty' => '(nessuna controparte)',
    'leg_count' => ':count pagamento|:count pagamenti',
    'legs_more' => '+ altri :count',
    'state_aria' => 'Stato: :state',

    'state' => [
        'candidate' => 'Candidato',
        'confirmed' => 'Confermata',
        'rejected' => 'Rifiutata',
    ],

    'kind' => [
        'paypal_funding' => 'Finanziamento PayPal',
        'ics_bulk_settle' => 'Regolamento iDEAL cumulativo',
        'funded_by_card_hint' => 'Finanziato da carta (suggerimento)',
        'refund_of_hint' => 'Rimborso (suggerimento)',
    ],
];
