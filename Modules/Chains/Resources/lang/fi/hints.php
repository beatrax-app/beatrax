<?php

declare(strict_types=1);

return [
    'page_title' => 'Ketjuvihjeet',
    'heading' => 'Vihjeet',
    'back_to_review' => '← Takaisin tarkistusjonoon',
    'subtitle' => 'Ehdokkaat, jotka täsmäytin antoi ilman vastaparia. Suoritusvihje poistuu itsestään, kun puuttuvat veloitukset saapuvat; muut jäävät, kunnes hylkäät ne täällä.',

    'empty_heading' => 'Ei käsiteltäviä vihjeitä',
    'empty_body' => 'Kun tunnistin nostaa esiin ketjun, jota se ei voinut ratkaista automaattisesti, se ilmestyy tänne.',

    'no_counterparty' => '(ei vastapuolta)',
    'unknown_account' => '(tuntematon tili)',

    'dismiss' => 'Ohita',
    'dismiss_aria' => 'Ohita vihje :id',
    'dismissed' => 'Vihje ohitettu.',

    'kind' => [
        'ics_bulk_settle' => 'iDEAL-koontitilitys (toleranssin ulkopuolella)',
        'funded_by_card_hint' => 'Rahoitettu kortilla (vihje)',
        'refund_of_hint' => 'Hyvitys (vihje)',
    ],

    'evidence' => [
        'tolerance' => 'Toleranssi: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'kiinteän liikkumavaran sisällä',
            'percent_2' => 'prosentuaalisen liikkumavaran sisällä',
            'exceeded' => 'liikkumavaran ulkopuolella',
            'refund_after_close' => 'hyvitys sulkemisen jälkeen',
        ],
        'delta_overpaid' => 'Maksettu :amount liikaa',
        'delta_underpaid' => 'Puuttuu :amount',
        'delta_balanced' => 'Täsmää tarkalleen',
        'covered' => 'Katetut tapahtumat: :count',
        'statement' => 'Korttilasku #:id',
        'card_last4' => 'Kortti, joka päättyy :last4',
        'original_reference' => 'Alkuperäinen tilausviite: :reference',
    ],
];
