<?php

declare(strict_types=1);

return [
    'page_title' => 'Kjedehint',
    'heading' => 'Hint',
    'back_to_review' => '← Tilbake til gjennomgangskøen',
    'subtitle' => 'Kandidater en matcher ga uten en tilsvarende motpart. Et oppgjørshint forsvinner av seg selv når de manglende postene kommer inn; resten blir stående til du avviser dem her.',

    'empty_heading' => 'Ingen hint å sortere',
    'empty_body' => 'Når en matcher finner en kjede den ikke kunne løse automatisk, dukker den opp her.',

    'no_counterparty' => '(ingen motpart)',
    'unknown_account' => '(ukjent konto)',

    'dismiss' => 'Lukk',
    'dismiss_aria' => 'Lukk hint :id',
    'dismissed' => 'Hintet er lukket.',

    'kind' => [
        'ics_bulk_settle' => 'Samlet iDEAL-oppgjør (utenfor toleransen)',
        'funded_by_card_hint' => 'Finansiert med kort (hint)',
        'refund_of_hint' => 'Refusjon (hint)',
    ],

    'evidence' => [
        'tolerance' => 'Toleranse: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'innenfor den faste marginen',
            'percent_2' => 'innenfor den prosentvise marginen',
            'exceeded' => 'utenfor marginen',
            'refund_after_close' => 'refusjon etter avslutning',
        ],
        'delta_overpaid' => 'Betalt :amount for mye',
        'delta_underpaid' => 'Mangler :amount',
        'delta_balanced' => 'Går akkurat opp',
        'covered' => 'Dekkede transaksjoner: :count',
        'statement' => 'Kortoppgjør #:id',
        'card_last4' => 'Kort som ender på :last4',
        'original_reference' => 'Opprinnelig ordrereferanse: :reference',
    ],
];
