<?php

declare(strict_types=1);

return [
    'page_title' => 'Ahelate vihjed',
    'heading' => 'Vihjed',
    'back_to_review' => '← Tagasi ülevaatusjärjekorda',
    'subtitle' => 'Kandidaadid, mille sobitaja andis ilma sobiva vasteta. Arvelduse vihje kaob ise, kui puuduvad kulud saabuvad; ülejäänud jäävad, kuni need siin kõrvale jätad.',

    'empty_heading' => 'Sortimiseks pole vihjeid',
    'empty_body' => 'Kui sobitaja leiab ahela, mida ta ei suutnud automaatselt lahendada, ilmub see siia.',

    'no_counterparty' => '(vastaspooleta)',
    'unknown_account' => '(tundmatu konto)',

    'dismiss' => 'Peida',
    'dismiss_aria' => 'Peida vihje :id',
    'dismissed' => 'Vihje on peidetud.',

    'kind' => [
        'ics_bulk_settle' => 'iDEALi koondarveldus (lubatud hälbest väljas)',
        'funded_by_card_hint' => 'Rahastatud kaardiga (vihje)',
        'refund_of_hint' => 'Tagasimakse (vihje)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerants: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'püsivahemiku piires',
            'percent_2' => 'protsendivahemiku piires',
            'exceeded' => 'väljaspool vahemikku',
            'refund_after_close' => 'tagasimakse pärast sulgemist',
        ],
        'delta_overpaid' => 'Ülemakstud :amount',
        'delta_underpaid' => 'Puudu :amount',
        'delta_balanced' => 'Läheb täpselt kokku',
        'covered' => 'Kaetud tehingud: :count',
        'statement' => 'Kaardiväljavõte nr :id',
        'card_last4' => 'Kaart, mis lõpeb :last4',
        'original_reference' => 'Algne tellimuse viide: :reference',
    ],
];
