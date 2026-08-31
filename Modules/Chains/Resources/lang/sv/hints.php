<?php

declare(strict_types=1);

return [
    'page_title' => 'Kedjeledtrådar',
    'heading' => 'Ledtrådar',
    'back_to_review' => '← Tillbaka till granskningskön',
    'subtitle' => 'Kandidater som en matchare gav utan motpart. En avräkningsledtråd försvinner av sig själv när de saknade posterna kommer in; övriga stannar tills du avfärdar dem här.',

    'empty_heading' => 'Inga ledtrådar att sortera',
    'empty_body' => 'När en matchare hittar en kedja som den inte kunde lösa automatiskt dyker den upp här.',

    'no_counterparty' => '(ingen motpart)',
    'unknown_account' => '(okänt konto)',

    'dismiss' => 'Stäng',
    'dismiss_aria' => 'Stäng ledtråd :id',
    'dismissed' => 'Ledtråden är stängd.',

    'kind' => [
        'ics_bulk_settle' => 'Samlad iDEAL-avräkning (utanför toleransen)',
        'funded_by_card_hint' => 'Finansierad med kort (ledtråd)',
        'refund_of_hint' => 'Återbetalning (ledtråd)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerans: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'inom den fasta marginalen',
            'percent_2' => 'inom den procentuella marginalen',
            'exceeded' => 'utanför marginalen',
            'refund_after_close' => 'återbetalning efter stängning',
        ],
        'delta_overpaid' => 'Betalt :amount för mycket',
        'delta_underpaid' => 'Saknas :amount',
        'delta_balanced' => 'Går jämnt ut',
        'covered' => 'Täckta transaktioner: :count',
        'statement' => 'Kortfaktura #:id',
        'card_last4' => 'Kort som slutar på :last4',
        'original_reference' => 'Ursprunglig orderreferens: :reference',
    ],
];
