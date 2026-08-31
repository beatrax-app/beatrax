<?php

declare(strict_types=1);

return [
    'page_title' => 'Sugestii de lanț',
    'heading' => 'Sugestii',
    'back_to_review' => '← Înapoi la coada de verificare',
    'subtitle' => 'Candidați emiși de un comparator fără un partener corespunzător. Un indiciu de decontare dispare singur de îndată ce sosesc cheltuielile lipsă; restul rămân până le respingi aici.',

    'empty_heading' => 'Nicio sugestie de triat',
    'empty_body' => 'Când un motor de potrivire scoate la iveală un lanț pe care nu l-a putut rezolva automat, acesta apare aici.',

    'no_counterparty' => '(fără contraparte)',
    'unknown_account' => '(cont necunoscut)',

    'dismiss' => 'Închide',
    'dismiss_aria' => 'Închide sugestia :id',
    'dismissed' => 'Sugestie închisă.',

    'kind' => [
        'ics_bulk_settle' => 'Decontare iDEAL în masă (în afara toleranței)',
        'funded_by_card_hint' => 'Finanțat prin card (sugestie)',
        'refund_of_hint' => 'Rambursare (sugestie)',
    ],

    'evidence' => [
        'tolerance' => 'Toleranță: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'în marja fixă',
            'percent_2' => 'în marja procentuală',
            'exceeded' => 'în afara marjei',
            'refund_after_close' => 'rambursare după închidere',
        ],
        'delta_overpaid' => 'Plătit în plus :amount',
        'delta_underpaid' => 'Lipsesc :amount',
        'delta_balanced' => 'Se închide exact',
        'covered' => 'Tranzacții acoperite: :count',
        'statement' => 'Extras de card nr. :id',
        'card_last4' => 'Card care se termină în :last4',
        'original_reference' => 'Referință comandă inițială: :reference',
    ],
];
