<?php

declare(strict_types=1);

return [
    'page_title' => 'Keten-hints',
    'heading' => 'Hints',
    'back_to_review' => '← Terug naar beoordelingswachtrij',
    'subtitle' => 'Kandidaten die een matcher heeft gemeld zonder bijpassende tegenhanger. Een afwikkelingshint verdwijnt vanzelf zodra de ontbrekende afschrijvingen binnenkomen; de rest blijft staan tot je ze hier afwijst.',

    'empty_heading' => 'Geen hints om te triëren',
    'empty_body' => 'Wanneer een matcher een keten aandraagt die niet automatisch kon worden opgelost, verschijnt die hier.',

    'no_counterparty' => '(geen tegenpartij)',
    'unknown_account' => '(onbekende rekening)',

    'dismiss' => 'Afwijzen',
    'dismiss_aria' => 'Hint :id afwijzen',
    'dismissed' => 'Hint afgewezen.',

    'kind' => [
        'ics_bulk_settle' => 'Bulk-iDEAL-afwikkeling (buiten tolerantie)',
        'funded_by_card_hint' => 'Gefinancierd met kaart (hint)',
        'refund_of_hint' => 'Terugbetaling (hint)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerantie: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'binnen de vaste marge',
            'percent_2' => 'binnen de procentuele marge',
            'exceeded' => 'buiten de marge',
            'refund_after_close' => 'terugbetaling na afsluiting',
        ],
        'delta_overpaid' => 'Te veel betaald: :amount',
        'delta_underpaid' => 'Te weinig betaald: :amount',
        'delta_balanced' => 'Klopt precies',
        'covered' => 'Gedekte transacties: :count',
        'statement' => 'Kaartafschrift #:id',
        'card_last4' => 'Kaart eindigend op :last4',
        'original_reference' => 'Oorspronkelijke bestelreferentie: :reference',
    ],
];
