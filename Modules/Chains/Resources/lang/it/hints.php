<?php

declare(strict_types=1);

return [
    'page_title' => 'Suggerimenti sulle catene',
    'heading' => 'Suggerimenti',
    'back_to_review' => '← Torna alla coda di revisione',
    'subtitle' => 'Candidati emessi da un matcher senza una controparte corrispondente. Un suggerimento di regolamento sparisce da solo quando arrivano le spese mancanti; gli altri restano finché non li ignori qui.',

    'empty_heading' => 'Nessun suggerimento da smistare',
    'empty_body' => 'Quando un matcher fa emergere una catena che non è riuscito a risolvere automaticamente, comparirà qui.',

    'no_counterparty' => '(nessuna controparte)',
    'unknown_account' => '(conto sconosciuto)',

    'dismiss' => 'Ignora',
    'dismiss_aria' => 'Ignora il suggerimento :id',
    'dismissed' => 'Suggerimento ignorato.',

    'kind' => [
        'ics_bulk_settle' => 'Regolamento iDEAL cumulativo (fuori tolleranza)',
        'funded_by_card_hint' => 'Finanziato da carta (suggerimento)',
        'refund_of_hint' => 'Rimborso (suggerimento)',
    ],

    'evidence' => [
        'tolerance' => 'Tolleranza: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'entro il margine fisso',
            'percent_2' => 'entro il margine percentuale',
            'exceeded' => 'fuori dal margine',
            'refund_after_close' => 'rimborso dopo la chiusura',
        ],
        'delta_overpaid' => 'Pagato in più: :amount',
        'delta_underpaid' => 'Mancano :amount',
        'delta_balanced' => 'Torna esattamente',
        'covered' => 'Transazioni coperte: :count',
        'statement' => 'Estratto conto carta n. :id',
        'card_last4' => 'Carta che termina con :last4',
        'original_reference' => 'Riferimento ordine originale: :reference',
    ],
];
