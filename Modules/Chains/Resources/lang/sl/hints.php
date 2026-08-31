<?php

declare(strict_types=1);

return [
    'page_title' => 'Namigi za verige',
    'heading' => 'Namigi',
    'back_to_review' => '← Nazaj na vrsto za pregled',
    'subtitle' => 'Kandidati, ki jih je ujemalnik oddal brez ustreznega partnerja. Namig o poravnavi izgine sam, ko prispejo manjkajoče bremenitve; ostali ostanejo, dokler jih tukaj ne zavrnete.',

    'empty_heading' => 'Ni namigov za triažo',
    'empty_body' => 'Ko matcher najde verigo, ki je ni mogel samodejno razrešiti, se ta pojavi tukaj.',

    'no_counterparty' => '(brez nasprotne stranke)',
    'unknown_account' => '(neznan račun)',

    'dismiss' => 'Opusti',
    'dismiss_aria' => 'Opusti namig :id',
    'dismissed' => 'Namig je opuščen.',

    'kind' => [
        'ics_bulk_settle' => 'Zbirna poravnava iDEAL (zunaj dopustnega odstopanja)',
        'funded_by_card_hint' => 'Financirano s kartico (namig)',
        'refund_of_hint' => 'Vračilo (namig)',
    ],

    'evidence' => [
        'tolerance' => 'Toleranca: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'znotraj fiksne tolerance',
            'percent_2' => 'znotraj odstotne tolerance',
            'exceeded' => 'zunaj tolerance',
            'refund_after_close' => 'vračilo po zaprtju',
        ],
        'delta_overpaid' => 'Preplačilo :amount',
        'delta_underpaid' => 'Manjka :amount',
        'delta_balanced' => 'Se ujema natančno',
        'covered' => 'Pokrite transakcije: :count',
        'statement' => 'Izpisek kartice št. :id',
        'card_last4' => 'Kartica, ki se konča na :last4',
        'original_reference' => 'Prvotna referenca naročila: :reference',
    ],
];
