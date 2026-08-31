<?php

declare(strict_types=1);

return [
    'page_title' => 'Ķēžu norādes',
    'heading' => 'Norādes',
    'back_to_review' => '← Atpakaļ uz pārskatīšanas rindu',
    'subtitle' => 'Kandidāti, ko saskaņotājs izdeva bez atbilstoša partnera. Norēķina norāde pazūd pati, tiklīdz pienāk trūkstošie izdevumi; pārējās paliek, līdz tās šeit noraidāt.',

    'empty_heading' => 'Nav norāžu, ko šķirot',
    'empty_body' => 'Kad sakritību meklētājs atradīs ķēdi, ko nevar atrisināt automātiski, tā parādīsies šeit.',

    'no_counterparty' => '(nav darījuma partnera)',
    'unknown_account' => '(nezināms konts)',

    'dismiss' => 'Aizvērt',
    'dismiss_aria' => 'Aizvērt norādi :id',
    'dismissed' => 'Norāde aizvērta.',

    'kind' => [
        'ics_bulk_settle' => 'Apkopots iDEAL norēķins (ārpus pielaides)',
        'funded_by_card_hint' => 'Finansēts ar karti (norāde)',
        'refund_of_hint' => 'Atmaksa (norāde)',
    ],

    'evidence' => [
        'tolerance' => 'Pielaide: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'fiksētās pielaides robežās',
            'percent_2' => 'procentuālās pielaides robežās',
            'exceeded' => 'ārpus pielaides',
            'refund_after_close' => 'atmaksa pēc slēgšanas',
        ],
        'delta_overpaid' => 'Pārmaksāts par :amount',
        'delta_underpaid' => 'Trūkst :amount',
        'delta_balanced' => 'Saskan precīzi',
        'covered' => 'Segtie darījumi: :count',
        'statement' => 'Kartes izraksts Nr. :id',
        'card_last4' => 'Karte, kas beidzas ar :last4',
        'original_reference' => 'Sākotnējā pasūtījuma atsauce: :reference',
    ],
];
